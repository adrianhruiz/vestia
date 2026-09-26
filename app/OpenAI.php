<?php
declare(strict_types=1);

final class OpenAI {
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
     * What this person wears, read from their photos and from whatever they chose to add.
     *
     * The photos may be of them or of looks they saved: the screen asks for either, and the
     * difference does not change the question. Both are what they want to be wearing.
     *
     * Three things the schema and the instructions are deliberately strict about.
     *
     * Every field may come back empty. A photo cannot show a size or a budget, and a model
     * asked for one anyway produces "M" and "hasta 120 €" that the person then reads as
     * something we knew about them. Those two are not in the schema at all; the rest are
     * allowed to be the empty string.
     *
     * `avoid` is filled from the note or not at all. A print not appearing in three photos
     * is not evidence that anybody dislikes it, and this is the field that decides what the
     * stylist will never propose again.
     *
     * And nothing describes the person. Age, body, weight, gender, origin: a photograph
     * invites all of it, it is not what was asked for, and reading a machine's opinion of
     * your body is not what anybody signed up for. The subject is the clothes.
     */
    public function readStyle(array $photos, string $note): array {
        // Built from the list StyleReading.php accepts back, so what is asked for and what is
        // kept are the same set by construction. strict mode wants every property required;
        // "may be empty" is carried by the instructions, not by making a field optional.
        $fields = array_merge(['summary'], STYLE_READING_KEYS);
        $schema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => $fields,
            'properties' => array_fill_keys($fields, ['type' => 'string']),
        ];
        $text = 'Lee mi estilo a partir de ' . (count($photos) === 1 ? 'esta foto' : 'estas ' . count($photos) . ' fotos') . '.';
        if ($note !== '') $text .= ' Además quiero contarte esto: ' . $note;

        $content = [['type' => 'input_text', 'text' => $text]];
        foreach ($photos as $photo) {
            $content[] = ['type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($photo))];
        }
        $payload = [
            'model' => env('OPENAI_MODEL', 'gpt-5.5'),
            'instructions' => 'Eres un estilista personal experto en tendencias actuales en España. '
                . 'Mira las fotos y describe el estilo de quien te las envía, en segunda persona y en español claro. '
                . 'Habla solo de ropa: prendas, cortes, tejidos, colores y cómo se combinan. '
                . 'No describas a la persona ni el lugar: nada de edad, cuerpo, peso, género ni origen. '
                . 'Deja vacío cualquier campo que no puedas deducir de lo que ves; inventarlo es peor que no decir nada. '
                . 'Rellena avoid solo si la nota dice expresamente qué no quiere ponerse. '
                . 'summary es una frase corta que resuma lo que has visto.',
            'input' => [['role' => 'user', 'content' => $content]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'style_reading', 'strict' => true, 'schema' => $schema]],
            // Five short fields. The wardrobe analysis needs 2500 because it writes five
            // outfits; this one has never come near 800.
            'max_output_tokens' => 800,
            'store' => false,
        ];
        $result = json_decode($this->outputText($this->request('/responses', $payload)), true);
        if (!is_array($result)) throw new RuntimeException('OpenAI no devolvió una lectura válida.');
        return $result;
    }

    public function outfitImage(array $outfit, array $garments): ?string {
        $prompt = 'Fotografía editorial vertical de un outfit completo presentado como flat lay limpio, sin modelo, fondo neutro cálido. Sin logos ni texto. Conjunto: ' . json_encode($this->outfitScene($outfit, $garments), JSON_UNESCAPED_UNICODE);
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

    /**
     * What drawing this look actually needs. The caller hands over the whole wardrobe
     * because that is what it has, but only the pieces in this outfit reach the prompt —
     * before, the full garment list was serialised into every image call, so a five-outfit
     * analysis sent it six times over. The reasoning and the shopping links go too: they
     * are for the person reading the card, not for the picture.
     */
    private function outfitScene(array $outfit, array $garments): array {
        $wanted = array_flip(array_map('intval', $outfit['garment_ids'] ?? []));
        $pieces = [];
        foreach ($garments as $garment) {
            if (!isset($wanted[(int) ($garment['id'] ?? 0)])) continue;
            $pieces[] = array_filter([
                'name' => $garment['name'] ?? null,
                'category' => $garment['category'] ?? null,
                'color' => $garment['color'] ?? null,
                'season' => $garment['season'] ?? null,
            ], static fn($value): bool => $value !== null && $value !== '');
        }
        return array_filter([
            'name' => $outfit['name'] ?? null,
            'occasion' => $outfit['occasion'] ?? null,
            'garments' => $pieces,
            // The look is only complete with the pieces the wardrobe is missing.
            'complementos' => array_values(array_filter((array) ($outfit['missing_items'] ?? []), 'is_string')),
        ], static fn($value): bool => $value !== null && $value !== '' && $value !== []);
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

