import { defineConfig, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

/**
 * Vite plugin that redirects dev CSS injection into Shadow DOM.
 * In dev mode, Vite injects styles into document.head via HMR.
 * This plugin patches the injection target to use our shadow root instead.
 */
function shadowDomCss(): Plugin {
  return {
    name: 'shadow-dom-css',
    enforce: 'post',
    transformIndexHtml(html) {
      // Inject a small script that makes Vite's CSS HMR target the shadow root
      const script = `
<script>
  // Patch for Shadow DOM CSS injection in dev mode
  (function() {
    var origAppend = document.head.appendChild.bind(document.head);
    var shadowHost = null;

    // Observer waits for shadow root to appear
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
  server: {
    port: 3001,
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
