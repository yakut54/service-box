import { defineConfig, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

/**
 * Vite plugin that redirects dev CSS injection into Shadow DOM.
 * In dev mode, Vite injects styles into document.head via HMR.
 * This plugin patches the injection target to use our shadow root instead.
 * Also fixes local dev: replaces <script src="/widget.js"> with a module import.
 */
function shadowDomCss(): Plugin {
  return {
    name: 'shadow-dom-css',
    enforce: 'post',
    transformIndexHtml(html, ctx) {
      // In dev mode: replace the compiled widget.js script tag with a live module import.
      // In prod/build mode: leave as-is (nginx serves /widget.js).
      if (ctx.server) {
        html = html.replace(
          '<script src="/widget.js"></script>',
          '<script type="module" src="/src/main.ts"></script>'
        )
      }

      // Inject a small script that makes Vite's CSS HMR target the shadow root.
      // Use DOMContentLoaded to guarantee document.body exists before observing.
      const script = `
<script>
  // Patch for Shadow DOM CSS injection in dev mode
  (function() {
    var origAppend = document.head.appendChild.bind(document.head);
    var shadowHost = null;

    function startObserver() {
      if (!document.body) return;
      var observer = new MutationObserver(function() {
        var el = document.getElementById('servicebox-widget');
        if (el && el.shadowRoot) {
          shadowHost = el;
          observer.disconnect();
          // Move any already-injected vite styles into shadow
          document.head.querySelectorAll('style[data-vite-dev-id]').forEach(function(s) {
            el.shadowRoot.appendChild(s);
          });
        }
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }

    // document.body may not exist yet when script runs in <head>
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', startObserver);
    } else {
      startObserver();
    }

    // Intercept future style insertions
    document.head.appendChild = function(node) {
      if (node.tagName === 'STYLE' && shadowHost && shadowHost.shadowRoot) {
        return shadowHost.shadowRoot.appendChild(node);
      }
      return origAppend(node);
    };
  })();
</script>`
      return html.replace('</head>', script + '\n</head>')
    },
  }
}

export default defineConfig({
  plugins: [vue(), shadowDomCss()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  server: {
    port: 3001,
    proxy: {
      // Proxy API calls to production server so shop data exists.
      '/api': {
        target: 'https://yakut54.ru',
        changeOrigin: true,
      },
    },
  },
  build: {
    lib: {
      entry: resolve(__dirname, 'src/main.ts'),
      name: 'ServiceBoxWidget',
      formats: ['iife'],
      fileName: () => 'widget.js',
    },
    rollupOptions: {
      output: {
        // No separate CSS file — everything inlined via ?inline import
        assetFileNames: 'widget[extname]',
      },
    },
    cssCodeSplit: false,
  },
})
