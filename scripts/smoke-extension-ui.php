#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Smoke test for extension UI manifest validation, kinds, /api/info card exposure,
 * and Extensions::availableList(). Runs against a throwaway SQLite DB and temp folder.
 *
 *   php scripts/smoke-extension-ui.php
 */

namespace WebringHub;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . '/../src/AppError.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Verify.php';
require_once __DIR__ . '/../src/Net.php';
require_once __DIR__ . '/../src/Site.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Extensions.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    fwrite($ok ? STDOUT : STDERR, ($ok ? '  ok   ' : '  FAIL ') . $label . "\n");
    if (!$ok) {
        $failures++;
    }
}

$tmpRoot = sys_get_temp_dir() . '/webring-ext-ui-' . bin2hex(random_bytes(4));
$extRoot = $tmpRoot . '/extensions';
mkdir($extRoot, 0777, true);
$dbPath = $tmpRoot . '/test.sqlite';

$config = Config::load([
    'db_driver' => 'sqlite',
    'sqlite_path' => $dbPath,
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $dbPath],
    'auto_migrate' => true,
]);
$pdo = Database::open($config);

/**
 * Helper to create a throwaway extension folder.
 *
 * @param array<string, mixed> $manifest
 */
function makeExt(string $extRoot, string $id, string $classShort, array $manifest, string $registerBody = ''): void
{
    $dir = $extRoot . '/' . $id;
    mkdir($dir . '/public', 0777, true);
    $fqcn = "WebringHub\\ExtTest\\{$classShort}";
    $manifest = array_merge([
        'id' => $id,
        'name' => $classShort,
        'version' => '1.0.0',
        'apiVersion' => 1,
        'class' => $fqcn,
        'description' => "Test {$id}",
    ], $manifest);
    file_put_contents($dir . '/extension.json', json_encode($manifest, JSON_PRETTY_PRINT));
    $php = <<<PHP
<?php
declare(strict_types=1);
namespace WebringHub\ExtTest;
use WebringHub\Extension;
use WebringHub\ExtensionContext;
final class {$classShort} implements Extension {
    public function register(ExtensionContext \$ctx): void {
        {$registerBody}
    }
}
PHP;
    file_put_contents($dir . '/Extension.php', $php);
}

// 1. Kind matrix: public, admin, both, headless
makeExt($extRoot, 'ext-pub', 'ExtPub', [
    'ui' => [
        'public' => [
            'title' => 'Public Toy',
            'tagline' => 'User facing only',
            'href' => '/ext/ext-pub/',
            'cta' => 'Launch',
            'art' => 'card.svg',
            'accent' => 'cyan',
        ],
    ],
]);

makeExt($extRoot, 'ext-adm', 'ExtAdm', [
    'ui' => [
        'admin' => [
            'panels' => [
                [
                    'id' => 'overview',
                    'title' => 'Overview',
                    'type' => 'stats',
                    'source' => 'v1/admin/stats',
                    'items' => [['key' => 'total', 'label' => 'Total']],
                ],
            ],
        ],
    ],
], "\$ctx->get('v1/admin/stats', fn () => ['total' => 1], ['admin' => true]);");

makeExt($extRoot, 'ext-both', 'ExtBoth', [
    'ui' => [
        'public' => [
            'title' => 'Both App',
            'tagline' => 'Public and admin',
            'href' => '/ext/ext-both/index.html',
            'cta' => 'Open',
            'art' => 'img/card.webp',
            'accent' => 'magenta',
        ],
        'admin' => [
            'panels' => [
                [
                    'id' => 'items',
                    'title' => 'Items',
                    'type' => 'table',
                    'source' => 'v1/admin/items',
                    'columns' => [
                        ['key' => 'name', 'label' => 'Name'],
                        ['key' => 'active', 'label' => 'Status', 'as' => 'badge'],
                    ],
                    'rowKey' => 'slug',
                    'actions' => [
                        [
                            'label' => 'Disable',
                            'method' => 'POST',
                            'path' => 'v1/admin/items/{slug}/toggle',
                            'body' => ['active' => false],
                            'when' => ['key' => 'active', 'equals' => true],
                            'confirm' => 'Disable item?',
                        ],
                    ],
                ],
            ],
        ],
    ],
], "\$ctx->get('v1/admin/items', fn () => ['items' => []], ['admin' => true]); \$ctx->post('v1/admin/items/{slug}/toggle', fn () => ['ok' => true], ['admin' => true]);");

makeExt($extRoot, 'ext-headless', 'ExtHeadless', []);

// 2. Rejections: bad href, bad accent, traversal art, non-admin panel source, over-limit panels
makeExt($extRoot, 'bad-href', 'BadHref', [
    'ui' => [
        'public' => [
            'title' => 'Evil',
            'href' => 'https://evil.example/',
            'accent' => 'cyan',
        ],
    ],
]);

makeExt($extRoot, 'bad-href-other', 'BadHrefOther', [
    'ui' => [
        'public' => [
            'title' => 'Other',
            'href' => '/ext/ext-pub/',
            'accent' => 'cyan',
        ],
    ],
]);

makeExt($extRoot, 'bad-accent', 'BadAccent', [
    'ui' => [
        'public' => [
            'title' => 'Rainbow',
            'href' => '/ext/bad-accent/',
            'accent' => 'red;background:url(x)',
        ],
    ],
]);

makeExt($extRoot, 'bad-art', 'BadArt', [
    'ui' => [
        'public' => [
            'title' => 'Art',
            'href' => '/ext/bad-art/',
            'art' => '../secret.svg',
            'accent' => 'yellow',
        ],
    ],
]);

makeExt($extRoot, 'bad-source', 'BadSource', [
    'ui' => [
        'admin' => [
            'panels' => [
                [
                    'id' => 'pub-route',
                    'title' => 'Oops',
                    'type' => 'stats',
                    'source' => 'v1/public/stats',
                ],
            ],
        ],
    ],
], "\$ctx->get('v1/public/stats', fn () => ['n' => 1]);");

$tooManyPanels = [];
for ($i = 1; $i <= 9; $i++) {
    $tooManyPanels[] = ['id' => "p{$i}", 'title' => "Panel {$i}", 'type' => 'stats', 'source' => 'v1/admin/s'];
}
makeExt($extRoot, 'bad-limit', 'BadLimit', [
    'ui' => ['admin' => ['panels' => $tooManyPanels]],
], "\$ctx->get('v1/admin/s', fn () => [], ['admin' => true]);");

// 3. Available (not enabled) extensions: one with fatal PHP that must NOT be executed, one with bad JSON
$unloadedDir = $extRoot . '/unloaded-boom';
mkdir($unloadedDir, 0777, true);
file_put_contents($unloadedDir . '/extension.json', json_encode([
    'id' => 'unloaded-boom',
    'name' => 'Unloaded Boom',
    'version' => '2.0.0',
    'apiVersion' => 1,
    'class' => 'WebringHub\\ExtTest\\UnloadedBoom',
    'description' => 'Must never be executed by availableList()',
    'ui' => ['public' => ['title' => 'Boom', 'href' => '/ext/unloaded-boom/']],
]));
file_put_contents($unloadedDir . '/Extension.php', "<?php exit(99);\n");

$brokenDir = $extRoot . '/broken-manifest';
mkdir($brokenDir, 0777, true);
file_put_contents($brokenDir . '/extension.json', '{not valid json');

$enabledIds = [
    'ext-pub',
    'ext-adm',
    'ext-both',
    'ext-headless',
    'bad-href',
    'bad-href-other',
    'bad-accent',
    'bad-art',
    'bad-source',
    'bad-limit',
];

$ext = Extensions::load(array_merge($config, ['extensions' => $enabledIds]), $pdo, $extRoot);
$adminById = [];
foreach ($ext->adminList() as $row) {
    $adminById[$row['id']] = $row;
}
$pubById = [];
foreach ($ext->publicList() as $row) {
    $pubById[$row['id']] = $row;
}

echo "kind matrix\n";
check(($adminById['ext-pub']['kind'] ?? '') === 'public' && $adminById['ext-pub']['uiError'] === null, 'public-only extension is kind=public');
check(($adminById['ext-adm']['kind'] ?? '') === 'admin' && count($adminById['ext-adm']['panels']) === 1, 'admin-only extension is kind=admin with panels');
check(($adminById['ext-both']['kind'] ?? '') === 'both' && count($adminById['ext-both']['panels']) === 1, 'both extension is kind=both');
check(($adminById['ext-headless']['kind'] ?? '') === 'headless' && $adminById['ext-headless']['uiError'] === null, 'headless extension is kind=headless');

echo "/api/info publicList exposure\n";
check(isset($pubById['ext-pub']['ui']['public']) && $pubById['ext-pub']['ui']['public']['art'] === '/ext/ext-pub/card.svg', 'public extension carries card fields in publicList');
check(isset($pubById['ext-both']['ui']['public']) && $pubById['ext-both']['ui']['public']['accent'] === 'magenta', 'both extension carries card fields in publicList');
check(!isset($pubById['ext-adm']['ui']), 'admin-only extension has NO card in publicList');
check(!isset($pubById['ext-headless']['ui']), 'headless extension has NO card in publicList');
check(count($ext->publicCards()) === 2, 'publicCards() returns only the 2 valid user-facing extensions');

echo "validation rejections\n";
foreach (['bad-href', 'bad-href-other', 'bad-accent', 'bad-art', 'bad-source', 'bad-limit'] as $badId) {
    check(
        ($adminById[$badId]['loaded'] ?? false) === true
        && ($adminById[$badId]['kind'] ?? '') === 'headless'
        && !empty($adminById[$badId]['uiError'])
        && !isset($pubById[$badId]['ui']),
        "{$badId}: still loads as headless with uiError and no public card"
    );
}

echo "availableList()\n";
$available = $ext->availableList();
$availById = [];
foreach ($available as $item) {
    $availById[$item['id']] = $item;
}
check(!isset($availById['ext-pub']) && !isset($availById['ext-adm']), 'availableList() excludes enabled extensions');
check(
    isset($availById['unloaded-boom'])
    && $availById['unloaded-boom']['problem'] === null
    && $availById['unloaded-boom']['kind'] === 'public'
    && str_contains($availById['unloaded-boom']['configLine'], "'unloaded-boom'"),
    'availableList() reads manifest without executing Extension.php and builds configLine'
);
check(
    isset($availById['broken-manifest']) && !empty($availById['broken-manifest']['problem']),
    'availableList() reports a bad manifest in problem'
);

// Cleanup
$pdo = null;
$it = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($tmpRoot, \FilesystemIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $file) {
    $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
}
@rmdir($tmpRoot);

echo $failures === 0 ? "all checks passed\n" : "{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
