<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Presets;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Vue 3 + Inertia.js + TypeScript + Vite preset.
 *
 * Installs:
 *   • Vue 3 + Vue Router (optional)
 *   • Inertia.js Vue adapter
 *   • TypeScript
 *   • Vite with Vue plugin
 *   • Starter app layout (App.vue, Pages/Dashboard.vue)
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
final class VuePreset implements PresetInterface
{
    public function name(): string
    {
        return 'vue';
    }

    public function description(): string
    {
        return 'Vue 3 + Inertia.js + TypeScript + Vite';
    }

    public function packages(): array
    {
        return [
            'vue'                => '^3.5.0',
            '@inertiajs/vue3'    => '^2.0.0',
            'laravel-vite-plugin'=> '^1.0.0',
        ];
    }

    public function devPackages(): array
    {
        return [
            'vitejs/plugin-vue'  => '^5.2.0',
            'typescript'         => '^5.6.0',
            'vue-tsc'            => '^2.1.0',
            'vite'               => '^6.0.0',
        ];
    }

    public function install(string $basePath, callable $output): void
    {
        $output('  Installing Vue 3 + Inertia + TypeScript + Vite...');

        // Create tsconfig.json
        $this->writeFile("{$basePath}/tsconfig.json", $this->tsconfig());
        $output('  ✓ Created tsconfig.json');

        // Create vite.config.ts
        $this->writeFile("{$basePath}/vite.config.ts", $this->viteConfig());
        $output('  ✓ Created vite.config.ts');

        // Create frontend entry point
        $this->ensureDir("{$basePath}/resources/js");
        $this->writeFile("{$basePath}/resources/js/app.ts", $this->entryPoint());
        $output('  ✓ Created resources/js/app.ts');

        // Create starter pages
        $this->ensureDir("{$basePath}/resources/js/Pages");
        $this->writeFile("{$basePath}/resources/js/Pages/Dashboard.vue", $this->dashboardPage());
        $output('  ✓ Created resources/js/Pages/Dashboard.vue');

        // Create layout
        $this->ensureDir("{$basePath}/resources/js/Layouts");
        $this->writeFile("{$basePath}/resources/js/Layouts/AppLayout.vue", $this->appLayout());
        $output('  ✓ Created resources/js/Layouts/AppLayout.vue');

        // Create types
        $this->writeFile("{$basePath}/resources/js/types.d.ts", $this->types());
        $output('  ✓ Created resources/js/types.d.ts');

        $output('  ✓ Vue preset installed. Run: npm install');
    }

    private function tsconfig(): string
    {
        return <<<'JSON'
{
    "compilerOptions": {
        "target": "ES2022",
        "module": "ESNext",
        "moduleResolution": "bundler",
        "strict": true,
        "esModuleInterop": true,
        "skipLibCheck": true,
        "forceConsistentCasingInFileNames": true,
        "baseUrl": ".",
        "paths": {
            "@/*": ["resources/js/*"]
        },
        "types": ["vite/client"]
    },
    "include": ["resources/js/**/*.ts", "resources/js/**/*.vue"]
}
JSON;
    }

    private function viteConfig(): string
    {
        return <<<'TS'
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { resolve } from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
});
TS;
    }

    private function entryPoint(): string
    {
        return <<<'TS'
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import type { PageProps } from './types';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
TS;
    }

    private function dashboardPage(): string
    {
        return <<<'VUE'
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{
    user: { name: string; email: string };
}>();
</script>

<template>
    <AppLayout title="Dashboard">
        <Head title="Dashboard" />
        <div class="p-8">
            <h1 class="text-2xl font-bold">Welcome, {{ user.name }}!</h1>
            <p class="text-gray-600 mt-2">{{ user.email }}</p>
        </div>
    </AppLayout>
</template>
VUE;
    }

    private function appLayout(): string
    {
        return <<<'VUE'
<script setup lang="ts">
defineProps<{
    title: string;
}>();
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <nav class="bg-white shadow-sm border-b">
            <div class="max-w-7xl mx-auto px-4 py-4">
                <span class="text-xl font-bold text-gray-800">{{ title }}</span>
            </div>
        </nav>
        <main class="max-w-7xl mx-auto py-6">
            <slot />
        </main>
    </div>
</template>
VUE;
    }

    private function types(): string
    {
        return <<<'TS'
import type { Page } from '@inertiajs/core';

export interface PageProps {
    [key: string]: unknown;
}

declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    const component: DefineComponent<{}, {}, any>;
    export default component;
}
TS;
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function writeFile(string $path, string $content): void
    {
        file_put_contents($path, $content);
    }
}
