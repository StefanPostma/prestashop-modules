<?php
/** Minimal client for the Anthropic Messages API and the OpenAI Chat Completions API (text + JPEG images). */
class KcmAiClient
{
    private $provider;
    private $key;
    private $model;

    public function __construct(string $provider, string $key, string $model)
    {
        $this->provider = $provider === 'openai' ? 'openai' : 'anthropic';
        $this->key = $key;
        $this->model = $model;
    }

    public static function isConfigured(): bool
    {
        $p = Configuration::get(Kcm_Aidescribe::PROVIDER) === 'openai' ? 'openai' : 'anthropic';
        $k = Configuration::get($p === 'openai' ? Kcm_Aidescribe::KEY_OPENAI : Kcm_Aidescribe::KEY_ANTHROPIC);
        return $k !== false && trim((string) $k) !== '';
    }

    public static function fromSettings(): self
    {
        $p = Configuration::get(Kcm_Aidescribe::PROVIDER) === 'openai' ? 'openai' : 'anthropic';
        $key = trim((string) Configuration::get($p === 'openai' ? Kcm_Aidescribe::KEY_OPENAI : Kcm_Aidescribe::KEY_ANTHROPIC));
        $model = trim((string) Configuration::get($p === 'openai' ? Kcm_Aidescribe::MODEL_OPENAI : Kcm_Aidescribe::MODEL_ANTHROPIC));
        if ($key === '') {
            throw new RuntimeException('Geen API-sleutel ingesteld voor ' . ($p === 'openai' ? 'OpenAI' : 'Anthropic') . ' (Modules > AI-productomschrijving > Configureren).');
        }
        return new self($p, $key, $model ?: ($p === 'openai' ? 'gpt-5' : 'claude-sonnet-5-5'));
    }

    /**
     * @param string[] $jpegFiles paths of JPEG files to send along
     * @return string the model's text answer
     */
    public function complete(string $system, string $user, array $jpegFiles, int $maxTokens = 1800): string
    {
        $images = [];
        foreach ($jpegFiles as $f) {
            if (is_file($f)) {
                $images[] = base64_encode((string) file_get_contents($f));
            }
        }
        if ($this->provider === 'openai') {
            $content = [['type' => 'text', 'text' => $user]];
            foreach ($images as $b64) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $b64, 'detail' => 'auto']];
            }
            $body = ['model' => $this->model, 'max_completion_tokens' => $maxTokens,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $content]]];
            $res = $this->post('https://api.openai.com/v1/chat/completions', ['Authorization: Bearer ' . $this->key], $body);
            $text = $res['choices'][0]['message']['content'] ?? null;
            if (!is_string($text)) {
                throw new RuntimeException('Onverwacht antwoord van OpenAI: ' . substr(json_encode($res), 0, 300));
            }
            return $text;
        }
        $content = [];
        foreach ($images as $b64) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $b64]];
        }
        $content[] = ['type' => 'text', 'text' => $user];
        $body = ['model' => $this->model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $content]]];
        $res = $this->post('https://api.anthropic.com/v1/messages', ['x-api-key: ' . $this->key, 'anthropic-version: 2023-06-01'], $body);
        $text = '';
        foreach ($res['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        if ($text === '') {
            throw new RuntimeException('Onverwacht antwoord van Anthropic: ' . substr(json_encode($res), 0, 300));
        }
        return $text;
    }

    private function post(string $url, array $headers, array $body): array
    {
        $json = json_encode($body);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers)]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Netwerkfout: ' . $err);
        }
        $res = json_decode((string) $raw, true);
        if ($code >= 400 || !is_array($res)) {
            $msg = is_array($res) ? ($res['error']['message'] ?? $res['error'] ?? null) : null;
            throw new RuntimeException('API-fout (HTTP ' . $code . '): ' . (is_string($msg) ? $msg : substr((string) $raw, 0, 300)));
        }
        return $res;
    }
}
