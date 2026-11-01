<?php

declare(strict_types=1);

namespace App\Support\Developer;

/**
 * Code samples for an operation, generated from the spec: the path with its
 * example parameters, the documented query examples, the example body and
 * an Idempotency-Key on every POST, in curl, PHP (Laravel's HTTP client and
 * Guzzle), JavaScript (fetch) and Python (requests). The token comes from
 * the environment variable NONOX_TOKEN (in Laravel, a config value).
 *
 * @phpstan-import-type Operation from ApiSpec
 */
final class CodeSamples
{
    /** Tab labels, keyed by sample id; the value's second item is the highlighter language. */
    public const array LANGUAGES = [
        'curl' => ['curl', 'bash'],
        'laravel' => ['PHP (Laravel)', 'php'],
        'guzzle' => ['PHP (Guzzle)', 'php'],
        'js' => ['JavaScript', 'js'],
        'python' => ['Python', 'python'],
    ];

    /**
     * @param  Operation  $operation
     * @return array<string, string> sample id => code
     */
    public static function for(array $operation, string $server): array
    {
        $request = self::request($operation, $server);

        return [
            'curl' => self::curl($request),
            'laravel' => self::laravel($request),
            'guzzle' => self::guzzle($request),
            'js' => self::javascript($request),
            'python' => self::python($request),
        ];
    }

    /**
     * @param  Operation  $operation
     * @return array{method: string, url: string, query: array<string, string>, json: mixed, multipart: bool, idempotent: bool, binary: bool}
     */
    public static function request(array $operation, string $server): array
    {
        $path = $operation['path'];
        $query = [];

        foreach ($operation['parameters'] as $parameter) {
            if ($parameter['in'] === 'path') {
                $path = str_replace('{'.$parameter['name'].'}', rawurlencode((string) ($parameter['example'] ?? $parameter['name'])), $path);
            }

            if ($parameter['in'] === 'query' && $parameter['example'] !== null) {
                $query[$parameter['name']] = is_bool($parameter['example']) ? ($parameter['example'] ? 'true' : 'false') : (string) $parameter['example'];
            }

            if ($parameter['in'] === 'query' && $parameter['name'] === 'limit') {
                $query = ['limit' => trim((string) ($parameter['schema']['default'] ?? '50'), '"'), ...$query];
            }
        }

        $contents = $operation['body']['contents'] ?? [];
        $multipart = isset($contents[0]) && $contents[0]['type'] === 'multipart/form-data';
        $json = null;

        if (! $multipart && isset($contents[0]['examples'][0])) {
            $json = json_decode($contents[0]['examples'][0]['json'], true);
        }

        $success = collect($operation['responses'])->first(fn (array $response): bool => str_starts_with($response['status'], '2'));
        $binary = $success !== null && $success['contents'] !== [] && ! str_contains($success['contents'][0]['type'], 'json');

        return [
            'method' => strtoupper($operation['method']),
            'url' => rtrim($server, '/').$path,
            'query' => $query,
            'json' => $json,
            'multipart' => $multipart,
            'idempotent' => $operation['method'] === 'post',
            'binary' => $binary,
        ];
    }

    /** @param array{method: string, url: string, query: array<string, string>, json: mixed, multipart: bool, idempotent: bool, binary: bool} $request */
    private static function curl(array $request): string
    {
        $url = $request['url'].($request['query'] === [] ? '' : '?'.http_build_query($request['query'], '', '&', PHP_QUERY_RFC3986));
        $lines = [$request['method'] === 'GET' ? "curl \"{$url}\"" : "curl -X {$request['method']} \"{$url}\""];
        $lines[] = '  -H "Authorization: Bearer $NONOX_TOKEN"';
        $lines[] = '  -H "Accept: application/json"';

        if ($request['idempotent']) {
            $lines[] = '  -H "Idempotency-Key: $(uuidgen)"';
        }

        if ($request['multipart']) {
            $lines[] = '  -F "file=@kvitto.pdf;type=application/pdf"';
        }

        if ($request['json'] !== null) {
            $lines[] = '  -H "Content-Type: application/json"';
            $lines[] = "  -d '".str_replace("'", "'\\''", ApiSpec::prettyJson($request['json']))."'";
        }

        if ($request['binary']) {
            $lines[] = '  -o kvitto.pdf';
        }

        return implode(" \\\n", $lines);
    }

    /** @param array{method: string, url: string, query: array<string, string>, json: mixed, multipart: bool, idempotent: bool, binary: bool} $request */
    private static function laravel(array $request): string
    {
        $code = "use Illuminate\\Support\\Facades\\Http;\n";
        $code .= $request['idempotent'] ? "use Illuminate\\Support\\Str;\n" : '';
        $code .= "\n\$response = Http::withToken(config('services.nonox.token'))\n    ->acceptJson()";

        if ($request['idempotent']) {
            $code .= "\n    ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])";
        }

        if ($request['multipart']) {
            $code .= "\n    ->attach('file', file_get_contents('kvitto.pdf'), 'kvitto.pdf')";
        }

        $method = strtolower($request['method']);
        $url = self::phpString($request['url']);
        $data = $request['json'] ?? ($request['query'] === [] ? null : self::typedQuery($request['query']));
        $code .= $data === null
            ? "\n    ->{$method}({$url});"
            : "\n    ->{$method}({$url}, ".self::php($data, 1).');';

        $code .= "\n\n\$response->throw();";
        $code .= $request['binary']
            ? "\n\nfile_put_contents('kvitto.pdf', \$response->body());"
            : ($request['method'] === 'DELETE' ? '' : "\n\n\$data = \$response->json('data');");

        return $code;
    }

    /** @param array{method: string, url: string, query: array<string, string>, json: mixed, multipart: bool, idempotent: bool, binary: bool} $request */
    private static function guzzle(array $request): string
    {
        $headers = ["'Authorization' => 'Bearer '.getenv('NONOX_TOKEN')", "'Accept' => 'application/json'"];

        if ($request['idempotent']) {
            $headers[] = "'Idempotency-Key' => bin2hex(random_bytes(16))";
        }

        $options = ["    'headers' => [\n        ".implode(",\n        ", $headers).",\n    ]"];

        if ($request['query'] !== []) {
            $options[] = "    'query' => ".self::php(self::typedQuery($request['query']), 1);
        }

        if ($request['json'] !== null) {
            $options[] = "    'json' => ".self::php($request['json'], 1);
        }

        if ($request['multipart']) {
            $options[] = "    'multipart' => [\n        ['name' => 'file', 'contents' => fopen('kvitto.pdf', 'r'), 'filename' => 'kvitto.pdf'],\n    ]";
        }

        if ($request['binary']) {
            $options[] = "    'sink' => 'kvitto.pdf'";
        }

        $code = "\$client = new \\GuzzleHttp\\Client(['timeout' => 30]);\n\n";
        $code .= "\$response = \$client->request('{$request['method']}', ".self::phpString($request['url']).", [\n".implode(",\n", $options).",\n]);";

        if (! $request['binary'] && $request['method'] !== 'DELETE') {
            $code .= "\n\n\$data = json_decode((string) \$response->getBody(), true, flags: JSON_THROW_ON_ERROR)['data'];";
        }

        return $code;
    }

    /** @param array{method: string, url: string, query: array<string, string>, json: mixed, multipart: bool, idempotent: bool, binary: bool} $request */
    private static function javascript(array $request): string
    {
        $code = '';

        if ($request['multipart']) {
            $code .= "import { readFile } from 'node:fs/promises';\n\nconst form = new FormData();\nform.append('file', new Blob([await readFile('kvitto.pdf')], { type: 'application/pdf' }), 'kvitto.pdf');\n\n";
        }

        $url = $request['url'];

        if ($request['query'] !== []) {
            $code .= 'const url = new URL('.self::jsString($url).");\n";

            foreach ($request['query'] as $name => $value) {
                $code .= 'url.searchParams.set('.self::jsString($name).', '.self::jsString($value).");\n";
            }

            $code .= "\n";
            $target = 'url';
        } else {
            $target = self::jsString($url);
        }

        $headers = ['    Authorization: `Bearer ${process.env.NONOX_TOKEN}`', "    Accept: 'application/json'"];

        if ($request['idempotent']) {
            $headers[] = "    'Idempotency-Key': crypto.randomUUID()";
        }

        if ($request['json'] !== null) {
            $headers[] = "    'Content-Type': 'application/json'";
        }

        $options = $request['method'] === 'GET' ? [] : ["  method: '{$request['method']}'"];
        $options[] = "  headers: {\n".implode(",\n", $headers).",\n  }";

        if ($request['json'] !== null) {
            $options[] = '  body: JSON.stringify('.self::indent(ApiSpec::prettyJson($request['json']), 1).')';
        }

        if ($request['multipart']) {
            $options[] = '  body: form';
        }

        $code .= "const response = await fetch({$target}, {\n".implode(",\n", $options).",\n});\n\n";
        $code .= "if (!response.ok) {\n  const problem = await response.json();\n  throw new Error(`\${problem.code}: \${problem.detail}`);\n}";

        if ($request['binary']) {
            $code .= "\n\nconst file = Buffer.from(await response.arrayBuffer());";
        } elseif ($request['method'] !== 'DELETE') {
            $code .= "\n\nconst { data } = await response.json();";
        }

        return $code;
    }

    /** @param array{method: string, url: string, query: array<string, string>, json: mixed, multipart: bool, idempotent: bool, binary: bool} $request */
    private static function python(array $request): string
    {
        $imports = ['os', 'requests'];

        if ($request['idempotent']) {
            $imports = ['os', 'uuid', 'requests'];
        }

        $headers = ['"Authorization": f"Bearer {os.environ[\'NONOX_TOKEN\']}"', '"Accept": "application/json"'];

        if ($request['idempotent']) {
            $headers[] = '"Idempotency-Key": str(uuid.uuid4())';
        }

        $arguments = ['    '.self::pyString($request['url'])];
        $arguments[] = "    headers={\n        ".implode(",\n        ", $headers).",\n    }";

        if ($request['query'] !== []) {
            $arguments[] = '    params='.self::pythonLiteral(self::typedQuery($request['query']), 1);
        }

        if ($request['json'] !== null) {
            $arguments[] = '    json='.self::pythonLiteral($request['json'], 1);
        }

        if ($request['multipart']) {
            $arguments[] = '    files={"file": ("kvitto.pdf", open("kvitto.pdf", "rb"), "application/pdf")}';
        }

        $arguments[] = '    timeout=30';

        $code = 'import '.implode("\n".'import ', $imports)."\n\n";
        $code .= 'response = requests.'.strtolower($request['method'])."(\n".implode(",\n", $arguments).",\n)\n";
        $code .= 'response.raise_for_status()';

        if ($request['binary']) {
            $code .= "\n\nwith open(\"kvitto.pdf\", \"wb\") as file:\n    file.write(response.content)";
        } elseif ($request['method'] !== 'DELETE') {
            $code .= "\n\ndata = response.json()[\"data\"]";
        }

        return $code;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, int|string>
     */
    private static function typedQuery(array $query): array
    {
        return array_map(fn (string $value): int|string => ctype_digit($value) ? (int) $value : $value, $query);
    }

    private static function php(mixed $value, int $depth): string
    {
        if (! is_array($value)) {
            return match (true) {
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                is_int($value), is_float($value) => (string) $value,
                default => self::phpString((string) $value),
            };
        }

        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('    ', $depth + 1);
        $list = array_is_list($value);
        $lines = [];

        foreach ($value as $key => $item) {
            $lines[] = $pad.($list ? '' : self::phpString((string) $key).' => ').self::php($item, $depth + 1).',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }

    private static function pythonLiteral(mixed $value, int $depth): string
    {
        if (! is_array($value)) {
            return match (true) {
                $value === null => 'None',
                is_bool($value) => $value ? 'True' : 'False',
                is_int($value), is_float($value) => (string) $value,
                default => self::pyString((string) $value),
            };
        }

        $list = array_is_list($value);

        if ($value === []) {
            return $list ? '[]' : '{}';
        }

        $pad = str_repeat('    ', $depth + 1);
        $lines = [];

        foreach ($value as $key => $item) {
            $lines[] = $pad.($list ? '' : self::pyString((string) $key).': ').self::pythonLiteral($item, $depth + 1).',';
        }

        return ($list ? '[' : '{')."\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).($list ? ']' : '}');
    }

    private static function phpString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    private static function jsString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    private static function pyString(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function indent(string $text, int $depth): string
    {
        return str_replace("\n", "\n".str_repeat('  ', $depth), $text);
    }
}
