import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

const apiTarget = process.env.VITE_API_PROXY || 'http://127.0.0.1:8000';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': {
        target: apiTarget,
        changeOrigin: true,
      },
      // Prod .htaccess rewrites /admin -> /api/admin.php. Mirror that in dev
      // so the URL works at localhost:5173/admin too.
      '/admin': {
        target: apiTarget,
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/admin/, '/api/admin.php'),
      },
      // Same trick for /share -> /api/share.php (the OG preview landing).
      '/share': {
        target: apiTarget,
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/share/, '/api/share.php'),
      },
    },
  },
});
