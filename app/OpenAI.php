<?php
declare(strict_types=1);

final class OpenAI {
    public function __construct(private string $key) {}

    public function analyze(array $garments, array $preferences): array {
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
        $payload = [
            'model' => env('OPENAI_MODEL', 'gpt-5.5'),
            'instructions' => 'Eres un estilista personal experto en tendencias actuales en España. Usa primero el armario real. Solo cuando falte una pieza, busca productos disponibles en webs oficiales de Zara, Massimo Dutti, Bershka, Pull&Bear, Stradivarius, Oysho o Lefties. No inventes disponibilidad, precio ni URL: si no puedes verificarlo, usa la página de búsqueda oficial y dilo en price_note. Devuelve español claro.',
            'input' => json_encode(['preferences' => $preferences, 'garments' => $garments], JSON_UNESCAPED_UNICODE),
            'tools' => [['type' => 'web_search', 'filters' => ['allowed_domains' => ['zara.com','massimodutti.com','bershka.com','pullandbear.com','stradivarius.com','oysho.com','lefties.com']]]],
            'include' => ['web_search_call.action.sources'],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'wardrobe_analysis', 'strict' => true, 'schema' => $schema]],
            // The schema tops out at five outfits with three shopping links each; 5000 was
            // paying for headroom nothing could use.
            'max_output_tokens' => 2500,
            'store' => false,
        ];
        $response = $this->request('/responses', $payload);
        $text = $this->outputText($response);
        $result = json_decode($text, true);
        if (!is_array($result)) throw new RuntimeException('OpenAI no devolvió un análisis válido.');
        $result['sources'] = $this->sources($response);
        return $result;
    }

    public function outfitImage(array $outfit, array $garments): ?string {
        $prompt = 'Fotografía editorial vertical de un outfit completo presentado como flat lay limpio, sin modelo, fondo neutro cálido. Sin logos ni texto. Conjunto: ' . json_encode(['outfit' => $outfit, 'garments' => $garments], JSON_UNESCAPED_UNICODE);
        $payload = [
            'model' => env('OPENAI_MODEL', 'gpt-5.5'), 'input' => $prompt,
            'tools' => [['type' => 'image_generation', 'model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2.5-flare'), 'size' => '1024x1536', 'quality' => 'medium']],
            'tool_choice' => ['type' => 'image_generation'], 'store' => false,
        ];
        $response = $this->request('/responses', $payload, 180);
        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'image_generation_call' && !empty($item['result'])) return $item['result'];
        }
        return null;
    }

    private function request(string $path, array $payload, int $timeout = 120): array {
        $ch = curl_init('https://api.openai.com/v1' . $path);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->key, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => $timeout]);
        $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) throw new RuntimeException('Error de red con OpenAI: ' . curl_error($ch));
        $data = json_decode($body, true);
        if ($status >= 400) throw new RuntimeException($data['error']['message'] ?? "OpenAI respondió {$status}");
        return is_array($data) ? $data : [];
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

