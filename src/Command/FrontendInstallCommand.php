<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Presets\{PresetInterface, ReactPreset, VuePreset};

/**
 * Install frontend SPA tooling (Inertia.js + Vite + React/Vue + Tailwind).
 *
 * Usage:
 *   php bin/ml frontend:install           # Default: React
 *   php bin/ml frontend:install --react   # Install React starter kit
 *   php bin/ml frontend:install --vue     # Install Vue starter kit
 */
#[CommandAttr('frontend:install', 'Install frontend SPA tooling (Inertia + Vite + React/Vue + Tailwind)')]
final class FrontendInstallCommand extends Command
{
    private const string REACT_DEPS = '"@inertiajs/react": "^2.0.0",\n        "react": "^18.3.0",\n        "react-dom": "^18.3.0"';
    private const string VUE_DEPS = '"@inertiajs/vue3": "^2.0.0",\n        "vue": "^3.4.0"';

    private const string REACT_DEV_DEPS = '"@types/react": "^18.3.0",\n        "@types/react-dom": "^18.3.0",\n        "@vitejs/plugin-react": "^4.3.0"';
    private const string VUE_DEV_DEPS = '"@vitejs/plugin-vue": "^5.0.0",\n        "vue-tsc": "^2.0.0"';

    protected function handle(): int
    {
        // Check for --preset flag (new preset system)
        $presetName = $this->option('preset');
        if ($presetName !== null) {
            return $this->installViaPreset($presetName);
        }

        // Legacy install path (backward compatible)
        $useVue = $this->hasOption('vue');
        $useReact = $this->hasOption('react') || !$useVue;
        $framework = $useVue ? 'Vue' : 'React';
        $entryFile = $useVue ? 'app.ts' : 'app.tsx';
        $pluginName = $useVue ? 'vue()' : 'react()';
        $pluginImport = $useVue
            ? "import vue from '@vitejs/plugin-vue';"
            : "import react from '@vitejs/plugin-react';";
        $pluginVar = $useVue ? 'vue' : 'react';

        $this->info("Installing {$framework} starter kit...");

        $basePath = function_exists('base_path') ? base_path() : getcwd();

        // 1. Create/update package.json
        $this->createPackageJson($basePath, $useVue);

        // 2. Create vite.config.ts
        $this->createViteConfig($basePath, $pluginImport, $pluginVar, $pluginName, $entryFile);

        // 3. Create tsconfig.json
        $this->createTsConfig($basePath, $useVue);

        // 4. Create Tailwind config
        $this->createTailwindConfig($basePath);

        // 5. Create PostCSS config
        $this->createPostcssConfig($basePath);

        // 6. Create CSS entry
        $this->createCssEntry($basePath);

        // 7. Create Inertia root template
        $this->createInertiaTemplate($basePath);

        // 8. Run npm install
        $this->info("Running npm install...");
        $npmResult = 0;
        @exec('cd ' . escapeshellarg($basePath) . ' && npm install 2>&1', $output, $npmResult);

        if ($npmResult !== 0) {
            $this->warn("npm install failed or npm is not installed.");
            $this->comment("Run 'npm install' manually in the project root.");
        } else {
            $this->info("✅ npm dependencies installed.");
        }

        $this->newLine();
        $this->alert("{$framework} starter kit installed!");
        $this->comment("Next steps:");
        $this->line("  1. Run 'npm run dev' to start the Vite dev server");
        $this->line("  2. Run 'composer serve' to start the PHP server");
        $this->line("  3. Visit http://localhost:8080");

        return self::SUCCESS;
    }

    /**
     * Install via the new preset system.
     */
    private function installViaPreset(string $presetName): int
    {
        $preset = $this->getPreset($presetName);
        if ($preset === null) {
            $this->error("Unknown preset: {$presetName}");
            $this->comment('Available presets: react, vue');
            return self::FAILURE;
        }

        $this->info('Installing preset: ' . $preset->description());
        $this->newLine();

        $basePath = function_exists('base_path') ? base_path() : getcwd();
        $output = fn(string $msg) => $this->line($msg);

        $preset->install($basePath, $output);

        $this->newLine();
        $this->alert($preset->description() . ' installed!');
        $this->comment('Next steps:');
        $this->line('  1. Run "npm install"');
        $this->line('  2. Run "npm run dev" to start Vite');
        $this->line('  3. Run "composer serve" to start the PHP server');

        return self::SUCCESS;
    }

    /**
     * Get a preset by name.
     */
    private function getPreset(string $name): ?PresetInterface
    {
        return match ($name) {
            'react' => new ReactPreset(),
            'vue'   => new VuePreset(),
            default => null,
        };
    }

    private function createPackageJson(string $basePath, bool $useVue): void
    {
        $deps = $useVue ? self::VUE_DEPS : self::REACT_DEPS;
        $devDeps = $useVue ? self::VUE_DEV_DEPS : self::REACT_DEV_DEPS;

        $content = <<<JSON
{
    "name": "monkeyslegion-skeleton",
    "private": true,
    "type": "module",
    "scripts": {
        "dev": "vite",
        "build": "vite build",
        "preview": "vite preview"
    },
    "dependencies": {
        {$deps}
    },
    "devDependencies": {
        {$devDeps},
        "autoprefixer": "^10.4.0",
        "postcss": "^8.4.0",
        "tailwindcss": "^3.4.0",
        "typescript": "^5.5.0",
        "vite": "^5.4.0"
    }
}
JSON;

        file_put_contents($basePath . '/package.json', $content);
        $this->info("✅ Created package.json");
    }

    private function createViteConfig(string $basePath, string $pluginImport, string $pluginVar, string $pluginName, string $entryFile): void
    {
        $content = <<<TS
import { defineConfig } from 'vite';
{$pluginImport}
import { resolve } from 'path';
import { fileURLToPath } from 'url';

const __dirname = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
    plugins: [{$pluginName}],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
    server: {
        port: 5173,
        hmr: {
            host: 'localhost',
        },
    },
    build: {
        outDir: 'public/build',
        manifest: 'manifest.json',
        rollupOptions: {
            input: 'resources/js/{$entryFile}',
        },
    },
});
TS;

        file_put_contents($basePath . '/vite.config.ts', $content);
        $this->info("✅ Created vite.config.ts");
    }

    private function createTsConfig(string $basePath, bool $useVue): void
    {
        $jsxSetting = $useVue ? '' : '"jsx": "react-jsx",';

        $content = <<<JSON
{
    "compilerOptions": {
        "target": "ES2022",
        "useDefineForClassFields": true,
        "lib": ["ES2022", "DOM", "DOM.Iterable"],
        "module": "ESNext",
        "skipLibCheck": true,
        "moduleResolution": "bundler",
        "allowImportingTsExtensions": true,
        "resolveJsonModule": true,
        "isolatedModules": true,
        "noEmit": true,
        {$jsxSetting}
        "strict": true,
        "noUnusedLocals": true,
        "noUnusedParameters": true,
        "noFallthroughCasesInSwitch": true,
        "baseUrl": ".",
        "paths": {
            "@/*": ["resources/js/*"]
        }
    },
    "include": ["resources/js"]
}
JSON;

        file_put_contents($basePath . '/tsconfig.json', $content);
        $this->info("✅ Created tsconfig.json");
    }

    private function createTailwindConfig(string $basePath): void
    {
        $content = <<<'JS'
/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/js/**/*.{tsx,ts,jsx,js,vue}',
        './resources/views/**/*.ml.php',
    ],
    theme: {
        extend: {
            colors: {
                'ml-primary': {
                    600: '#4f46e5',
                    700: '#4338ca',
                    100: '#e0e7ff',
                },
            },
        },
    },
    plugins: [],
};
JS;

        file_put_contents($basePath . '/tailwind.config.js', $content);
        $this->info("✅ Created tailwind.config.js");
    }

    private function createPostcssConfig(string $basePath): void
    {
        $content = <<<JS
export default {
    plugins: {
        tailwindcss: {},
        autoprefixer: {},
    },
};
JS;

        file_put_contents($basePath . '/postcss.config.js', $content);
        $this->info("✅ Created postcss.config.js");
    }

    private function createCssEntry(string $basePath): void
    {
        $cssDir = $basePath . '/resources/css';
        if (!is_dir($cssDir)) {
            @mkdir($cssDir, 0755, true);
        }

        $content = <<<CSS
@tailwind base;
@tailwind components;
@tailwind utilities;

@layer components {
    .btn { @apply inline-flex items-center px-4 py-2 rounded-lg font-medium transition-colors; }
    .btn-primary { @apply btn bg-ml-primary-600 text-white hover:bg-ml-primary-700; }
    .input { @apply w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-ml-primary-500; }
    .card { @apply bg-white rounded-lg shadow-sm border border-gray-200 p-6; }
}
CSS;

        file_put_contents($cssDir . '/app.css', $content);
        $this->info("✅ Created resources/css/app.css");
    }

    private function createInertiaTemplate(string $basePath): void
    {
        $viewsDir = $basePath . '/resources/views/layouts';
        if (!is_dir($viewsDir)) {
            @mkdir($viewsDir, 0755, true);
        }

        $content = <<<'PHP'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'MonKeysLegion' }}</title>
    @vite(['resources/js/app.tsx'])
</head>
<body>
    <div id="app" data-page="{{ $page }}"></div>
</body>
</html>
PHP;

        file_put_contents($viewsDir . '/inertia-app.ml.php', $content);
        $this->info("✅ Created resources/views/layouts/inertia-app.ml.php");
    }
}
