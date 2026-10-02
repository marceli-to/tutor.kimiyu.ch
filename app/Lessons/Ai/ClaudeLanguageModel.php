<?php

namespace App\Lessons\Ai;

use Anthropic\Beta\Messages\BetaBase64ImageSource;
use Anthropic\Beta\Messages\BetaBase64ImageSource\MediaType;
use Anthropic\Beta\Messages\BetaImageBlockParam;
use Anthropic\Beta\Messages\BetaJSONOutputFormat;
use Anthropic\Beta\Messages\BetaMessageParam;
use Anthropic\Beta\Messages\BetaOutputConfig;
use Anthropic\Beta\Messages\BetaOutputConfig\Effort;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaTextBlockParam;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Lib\Streaming\MessageAccumulator;

/**
 * Claude API über das offizielle PHP-SDK.
 *
 * - Strukturierte Ausgabe per JSON-Schema (output_config.format)
 * - Modell und Effort kommen pro Schritt aus dem ModelRequest (config/lessons.php)
 * - Streaming, weil Antworten gross sein können und sonst HTTP-Timeouts drohen
 * - Kein Prompt-Caching: Lernseiten entstehen zu selten, der Cache (5 Min.) wurde nie gelesen
 *   und das Schreiben kostet 25 % mehr als normaler Input
 * - Serverseitiger Ersatz (fallbacks), falls das Modell eine Anfrage ablehnt
 */
class ClaudeLanguageModel implements LanguageModel
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        private Client $client,
        private bool $fallbacks,
        private int $timeout,
    ) {}

    public function generate(ModelRequest $request): ModelResponse
    {
        // Tippfehler in LESSON_EFFORT_* oder ANTHROPIC_EFFORT: Schritt sauber abbrechen statt den Job abstürzen lassen
        $effort = Effort::tryFrom($request->effort());

        if ($effort === null) {
            throw new ModelException(
                'Die KI ist falsch eingerichtet.',
                "Unbekannter Effort «{$request->effort()}» für Schritt {$request->step}. Erlaubt: ".implode(', ', array_column(Effort::cases(), 'value')),
            );
        }

        try {
            $stream = $this->client->beta->messages->createStream(
                maxTokens: $request->maxTokens,
                model: $request->model(),
                system: $request->system,
                messages: [
                    BetaMessageParam::with(content: $this->content($request), role: 'user'),
                ],
                outputConfig: BetaOutputConfig::with(
                    effort: $effort,
                    format: BetaJSONOutputFormat::with(schema: $request->schema),
                ),
                fallbacks: $this->fallbacks ? 'default' : null,
                betas: $this->fallbacks ? [self::FALLBACK_BETA] : null,
                requestOptions: ['timeout' => (float) $this->timeout],
            );

            $accumulator = MessageAccumulator::forBetaMessages();
            foreach ($stream as $event) {
                $accumulator->accumulate($event);
            }
            $message = $accumulator->message();
        } catch (AuthenticationException $e) {
            throw new ModelException('Der Zugang zur KI ist falsch eingerichtet.', $e->getMessage(), previous: $e);
        } catch (BadRequestException $e) {
            throw new ModelException('Die Anfrage an die KI war ungültig.', $e->getMessage(), previous: $e);
        } catch (RateLimitException $e) {
            throw new ModelException('Die KI ist gerade ausgelastet.', $e->getMessage(), retryable: true, previous: $e);
        } catch (APIStatusException $e) {
            $retryable = ($e->status ?? 0) >= 500;

            throw new ModelException('Die KI hat mit einem Fehler geantwortet.', $e->getMessage(), $retryable, $e);
        } catch (APIConnectionException $e) {
            throw new ModelException('Die KI war nicht erreichbar.', $e->getMessage(), retryable: true, previous: $e);
        }

        $usage = $message->usage;
        $response = fn (array $data) => new ModelResponse(
            data: $data,
            model: $message->model,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheReadTokens: $usage->cacheReadInputTokens ?? 0,
            cacheWriteTokens: $usage->cacheCreationInputTokens ?? 0,
        );

        if ($message->stopReason === 'refusal') {
            throw new UsageAwareModelException(
                'Die KI hat die Anfrage abgelehnt. Bitte andere Fotos verwenden.',
                'refusal: '.($message->stopDetails->category ?? 'unbekannt'),
                $response([]),
            );
        }

        if ($message->stopReason === 'max_tokens') {
            throw new UsageAwareModelException(
                'Die Antwort der KI war zu lang und wurde abgeschnitten.',
                'max_tokens',
                $response([]),
                retryable: true,
            );
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $text .= $block->text;
            }
        }

        $data = json_decode($text, true);

        if (! is_array($data)) {
            throw new UsageAwareModelException('Die KI hat kein gültiges JSON geliefert.', mb_substr($text, 0, 500), $response([]));
        }

        return $response($data);
    }

    /**
     * Bilder vor dem Text, wie von Anthropic empfohlen.
     *
     * @return list<BetaImageBlockParam|BetaTextBlockParam>
     */
    private function content(ModelRequest $request): array
    {
        $content = [];

        foreach ($request->images as $image) {
            $content[] = BetaImageBlockParam::with(
                source: BetaBase64ImageSource::with(data: base64_encode($image['data']), mediaType: MediaType::from($image['mime'])),
            );
        }

        $content[] = BetaTextBlockParam::with(text: $request->prompt);

        return $content;
    }
}
