<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Presets;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * React + Inertia + TypeScript + Vite preset.
 *
 * Installs:
 *   • React 19 + React DOM
 *   • Inertia.js React adapter
 *   • TypeScript
 *   • Vite with React plugin
 *   • Tailwind CSS (optional)
 *   • Starter app layout (App.tsx, Pages/Dashboard.tsx)
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
final class ReactPreset implements PresetInterface
{
    public function name(): string
    {
        return 'react';
    }

    public function description(): string
    {
        return 'React 19 + Inertia.js + TypeScript + Vite';
    }

    public function packages(): array
    {
        return [
            'react'              => '^19.0.0',
            'react-dom'          => '^19.0.0',
            '@inertiajs/react'   => '^2.0.0',
            'laravel-vite-plugin'=> '^1.0.0',
        ];
    }

    public function devPackages(): array
    {
        return [
            '@types/react'       => '^19.0.0',
            '@types/react-dom'   => '^19.0.0',
            '@vitejs/plugin-react' => '^4.3.0',
            'typescript'         => '^5.6.0',
            'vite'               => '^6.0.0',
        ];
    }

    public function install(string $basePath, callable $output): void
    {
        $output('  Installing React + Inertia + TypeScript + Vite...');

        // Create tsconfig.json
        $this->writeFile("{$basePath}/tsconfig.json", $this->tsconfig());
        $output('  ✓ Created tsconfig.json');

        // Create vite.config.ts
        $this->writeFile("{$basePath}/vite.config.ts", $this->viteConfig());
        $output('  ✓ Created vite.config.ts');

        // Create frontend entry point
        $this->ensureDir("{$basePath}/resources/js");
        $this->writeFile("{$basePath}/resources/js/app.tsx", $this->entryPoint());
        $output('  ✓ Created resources/js/app.tsx');

        // Create starter pages
        $this->ensureDir("{$basePath}/resources/js/Pages");
        $this->writeFile("{$basePath}/resources/js/Pages/Dashboard.tsx", $this->dashboardPage());
        $output('  ✓ Created resources/js/Pages/Dashboard.tsx');

        // Create layout
        $this->ensureDir("{$basePath}/resources/js/Layouts");
        $this->writeFile("{$basePath}/resources/js/Layouts/AppLayout.tsx", $this->appLayout());
        $output('  ✓ Created resources/js/Layouts/AppLayout.tsx');

        // Create types
        $this->writeFile("{$basePath}/resources/js/types.d.ts", $this->types());
        $output('  ✓ Created resources/js/types.d.ts');

        $output('  ✓ React preset installed. Run: npm install');
    }

    private function tsconfig(): string
    {
        return <<<'JSON'
{
    "compilerOptions": {
        "target": "ES2022",
        "module": "ESNext",
        "moduleResolution": "bundler",
        "jsx": "react-jsx",
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
    "include": ["resources/js/**/*.ts", "resources/js/**/*.tsx"]
}
JSON;
    }

    private function viteConfig(): string
    {
        return <<<'TS'
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { resolve } from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
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
        return <<<'TSX'
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { PageProps } from './types';

createInertiaApp<{ props: PageProps }>({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
        const page = pages[`./Pages/${name}.tsx`];
        if (!page) throw new Error(`Unknown page: ${name}`);
        return page as any;
    },
    setup: ({ el, App, props }) => {
        createRoot(el).render(<App {...props} />);
    },
});
TSX;
    }

    private function dashboardPage(): string
    {
        return <<<'TSX'
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import type { PageProps } from '@/types';

interface DashboardProps extends PageProps {
    user: { name: string; email: string };
}

export default function Dashboard({ user }: DashboardProps) {
    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />
            <div className="p-8">
                <h1 className="text-2xl font-bold">Welcome, {user.name}!</h1>
                <p className="text-gray-600 mt-2">{user.email}</p>
            </div>
        </AppLayout>
    );
}
TSX;
    }

    private function appLayout(): string
    {
        return <<<'TSX'
import { ReactNode } from 'react';

interface AppLayoutProps {
    title: string;
    children: ReactNode;
}

export default function AppLayout({ title, children }: AppLayoutProps) {
    return (
        <div className="min-h-screen bg-gray-50">
            <nav className="bg-white shadow-sm border-b">
                <div className="max-w-7xl mx-auto px-4 py-4">
                    <span className="text-xl font-bold text-gray-800">{title}</span>
                </div>
            </nav>
            <main className="max-w-7xl mx-auto py-6">
                {children}
            </main>
        </div>
    );
}
TSX;
    }

    private function types(): string
    {
        return <<<'TS'
import type { Page } from '@inertiajs/core';

export interface PageProps {
    [key: string]: unknown;
}

export type AppPage = Page<PageProps>;
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
