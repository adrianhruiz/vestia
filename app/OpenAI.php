<?php
declare(strict_types=1);

final class OpenAI {
    /**
     * Longest a call to the model may take. The analysis is the slow one -- it may run a web
     * search before it writes anything -- and it has never needed more than this.
     */
    private const TIMEOUT = 120;

    public function __construct(private string $key) {}

    public function analyze(array $garments, array $preferences, bool $withShopping = true, string $budget = ''): array {
        $schema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['summary','palette','gaps','outfits'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'palette' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 8],
                'gaps' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 6],
                'outfits' => ['type' => 'array', 'minItems' => 3, 'maxItems' => 5, 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['name','occasion','reason','garment_ids','missing_items','shopping'],
                    'properties' => [
                        'name' => ['type' => 'string'], 'occasion' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                        'garment_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'missing_items' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'shopping' => ['type' => 'array', 'maxItems' => 3, 'items' => [
                            'type' => 'object', 'additionalProperties' => false,
                            'required' => ['brand','name','url','price_note'],
                            'properties' => [
                                'brand' => ['type' => 'string'], 'name' => ['type' => 'string'],
                                'url' => ['type' => 'string'], 'price_note' => ['type' => 'string']
                            ]
                        ]]
                    ]
                ]]
            ]
        ];
        $shoppingRules = 'Solo cuando falte una pieza, busca productos disponibles en webs oficiales de Zara, Massimo Dutti, Bershka, Pull&Bear, Stradivarius, Oysho o Lefties. No inventes disponibilidad, precio ni URL: si no puedes verificarlo, usa la página de búsqueda oficial y dilo en price_note.';
        // The budget is about this shopping trip, so it is said with the shopping rules and
        // not among the things we know about the person.
        if ($withShopping && $budget !== '') $shoppingRules .= ' El presupuesto para completar un outfit es: ' . $budget . '. No propongas nada que lo pase.';
        $wardrobeOnly = 'Trabaja únicamente con el armario real: no propongas compras y deja shopping vacío en todos los outfits. Sigue indicando en missing_items lo que le falte al armario.';
        $payload = [
            'model' => env('OPENAI_MODEL', 'gpt-5.5'),
            'instructions' => 'Eres un estilista personal experto en tendencias actuales en España. Usa primero el armario real. ' . ($withShopping ? $shoppingRules : $wardrobeOnly) . ' Devuelve español claro.',
            'input' => json_encode(['preferences' => $preferences, 'garments' => $garments], JSON_UNESCAPED_UNICODE),
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'wardrobe_analysis', 'strict' => true, 'schema' => $schema]],
            // The schema tops out at five outfits with three shopping links each; 5000 was
            // paying for headroom nothing could use.
            'max_output_tokens' => 2500,
            'store' => false,
        ];
        // The search is billed per call and its results land in the input tokens, so it only
        // runs when the person actually asked for things to buy.
        if ($withShopping) {
            $payload['tools'] = [['type' => 'web_search', 'filters' => ['allowed_domains' => ['zara.com','massimodutti.com','bershka.com','pullandbear.com','stradivarius.com','oysho.com','lefties.com']]]];
            $payload['include'] = ['web_search_call.action.sources'];
        }
        $response = $this->request('/responses', $payload);
        $text = $this->outputText($response);
        $result = json_decode($text, true);
        if (!is_array($result)) throw new RuntimeException('OpenAI no devolvió un análisis válido.');
        $result['sources'] = $this->sources($response);
        return $result;
    }

    /**
     * What this person wears, read from the outfits they put together themselves.
     *
     * The input used to be photographs of the person. Two of their own looks answer the same
     * question, and answer it about the clothes rather than about them: a garment they chose,
     * in a colour they bought, next to what they decided it goes with. It is also the one kind
     * of evidence they can correct, because it is their own wardrobe.
     *
     * Three things the schema and the instructions are deliberately strict about.
     *
     * Every field may come back empty, and none of them is allowed to be invented. Two looks
     * cannot tell you what somebody wears to a wedding, and a model asked anyway will say
     * something the person then reads as a thing we knew about them.
     *
     * `avoid` is not asked for at all, and is not in STYLE_READING_KEYS either. A print that
     * does not appear in two outfits is not evidence anybody dislikes it, and that field is
     * what decides what the stylist will never propose again.
     *
     * And nothing describes the person. Age, body, weight, gender, origin: none of it is in
     * the input any more, and none of it has any business in the output. The subject is the
     * clothes.
     */
    public function readStyle(array $outfits): array {
        // Built from the list StyleReading.php accepts back, so what is asked for and what is
        // kept are the same set by construction. strict mode wants every property required;
        // "may be empty" is carried by the instructions, not by making a field optional.
        $fields = array_merge(['summary'], STYLE_READING_KEYS);
        $schema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => $fields,
            'properties' => array_fill_keys($fields, ['type' => 'string']),
        ];
        $payload = [
            'model' => env('OPENAI_MODEL', 'gpt-5.5'),
            // style is the one field that runs long, and the only one the cap ever bit: a
            // stylist that does not know the limit writes past it and has mb_substr() end
            // the sentence mid-word. The others are a colour or two and an occasion or two,
            // and handing them the same budget only invites them to fill it.
            // STYLE_PROFILE_MAX_LENGTH is named rather than repeated as a number, so the
            // length asked for and the length stored cannot drift apart.
            'instructions' => 'Eres un estilista personal experto en tendencias actuales en España. '
                . 'Te paso ' . count($outfits) . ' outfits que esta persona ha montado con su propia ropa. '
                . 'Describe su estilo a partir de ellos, en segunda persona y en español claro. '
                . 'Habla solo de ropa: prendas, cortes, tejidos, colores y cómo las combina. '
                . 'No describas a la persona: nada de edad, cuerpo, peso, género ni origen. '
                . 'Deja vacío cualquier campo que no puedas deducir de estos outfits; inventarlo es peor que no decir nada. '
                . 'style, ' . STYLE_PROFILE_MAX_LENGTH . ' caracteres como mucho: es un perfil que la persona va a leer y corregir, no un informe. '
                . 'Si no cabe todo, quédate con lo que se repite en los dos outfits. '
                . 'summary es una frase corta que resuma lo que has visto.',
            'input' => json_encode(['outfits' => $outfits], JSON_UNESCAPED_UNICODE),
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'style_reading', 'strict' => true, 'schema' => $schema]],
            // Four short fields. The wardrobe analysis needs 2500 because it writes five
            // outfits; this one has never come near 800.
            'max_output_tokens' => 800,
            'store' => false,
        ];
        $result = json_decode($this->outputText($this->request('/responses', $payload)), true);
        if (!is_array($result)) throw new RuntimeException('OpenAI no devolvió una lectura válida.');
        return $result;
    }

    private function request(string $path, array $payload): array {
        require_once dirname(__DIR__) . '/log/app/Capture.php';
        $trace = VestiaLogCapture::start($path, $payload, $this->key);
        $started = microtime(true); $requestId = ''; $status = 0; $data = null; $error = null;
        $ch = curl_init('https://api.openai.com/v1' . $path);
        try {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->key, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$requestId): int {
                    if (stripos($header, 'x-request-id:') === 0) $requestId = trim(substr($header, 13));
                    return strlen($header);
                }]);
            $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($body === false) throw new RuntimeException('Error de red con OpenAI: ' . curl_error($ch));
            $data = json_decode($body, true);
            if (!is_array($data)) {
                $data = ['unparsed_body' => $body];
                throw new RuntimeException('OpenAI no devolvió una respuesta JSON válida.');
            }
            if ($status >= 400) throw new RuntimeException($data['error']['message'] ?? "OpenAI respondió {$status}");
            return $data;
        } catch (Throwable $e) { $error = $e->getMessage(); throw $e; }
        finally {
            VestiaLogCapture::finish($trace, $data, $status, microtime(true) - $started, $error, $requestId, $this->key);
            curl_close($ch);
        }
    }

    private function outputText(array $response): string {
        foreach ($response['output'] ?? [] as $item) foreach ($item['content'] ?? [] as $content)
            if (($content['type'] ?? '') === 'output_text') return (string)($content['text'] ?? '');
        return '';
    }

    private function sources(array $response): array {
        $sources = [];
        foreach ($response['output'] ?? [] as $item) foreach (($item['action']['sources'] ?? []) as $source)
            if (!empty($source['url'])) $sources[$source['url']] = ['url' => $source['url'], 'title' => $source['title'] ?? parse_url($source['url'], PHP_URL_HOST)];
        return array_values($sources);
    }
}
