import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// relative base: the build is served under /<backend.dirname>/ by \Core\Backend (folder of the config), assets by apache
// dev: npm run dev, /api is proxied to the local php site (same origin, the session cookie works)
export default defineConfig({
	base: './',
	plugins: [react()],
	build: {
		outDir: '../core/backend/dist',
		emptyOutDir: true,
	},
	server: {
		proxy: {
			'/api': {
				target: process.env.UNEE_API_URL ?? 'https://unee.local:4433',
				changeOrigin: true,
				secure: false, // local certificate (mkcert) is not in the node trust store
			},
		},
	},
})
