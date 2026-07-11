import { defineConfig } from 'vitest/config';

export default defineConfig({
    resolve: {
        alias: {
            '@battlefield': new URL('./resources/js/battlefield', import.meta.url).pathname,
        },
    },
    test: {
        include: ['tests/js/**/*.test.js'],
    },
});
