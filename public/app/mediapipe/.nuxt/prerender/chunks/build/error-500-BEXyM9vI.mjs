import { _ as _plugin_vue_export_helper_default, u as useHead$1 } from '../virtual/entry.mjs';
import { useSSRContext, mergeProps } from 'file:///home/stedam-app/nuxt-app/node_modules/vue/index.mjs';
import { ssrRenderAttrs, ssrInterpolate } from 'file:///home/stedam-app/nuxt-app/node_modules/vue/server-renderer/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/nostics/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/nostics/dist/formatters/ansi.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/nuxt/node_modules/hookable/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/unctx/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/h3/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/ufo/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/ofetch/dist/node.mjs';
import '../_/renderer.mjs';
import '../nitro/nitro.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/destr/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/hookable/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/node-mock-http/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/unstorage/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/unstorage/drivers/fs.mjs';
import 'node:crypto';
import 'node:fs/promises';
import 'node:path';
import 'file:///home/stedam-app/nuxt-app/node_modules/unstorage/drivers/fs-lite.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/unstorage/drivers/lru-cache.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/ohash/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/klona/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/defu/dist/defu.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/scule/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/nitropack/node_modules/unctx/dist/index.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/radix3/dist/index.mjs';
import 'node:fs';
import 'node:url';
import 'file:///home/stedam-app/nuxt-app/node_modules/pathe/dist/index.mjs';
import 'node:async_hooks';
import 'file:///home/stedam-app/nuxt-app/node_modules/unhead/dist/server.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/unhead/dist/legacy.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/unhead/dist/plugins.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/vue-bundle-renderer/dist/runtime.mjs';
import 'file:///home/stedam-app/nuxt-app/node_modules/devalue/index.js';
import 'file:///home/stedam-app/nuxt-app/node_modules/unhead/dist/utils.mjs';

var _sfc_main = {
  __name: "error-500",
  __ssrInlineRender: true,
  props: {
    appName: {
      type: String,
      default: "Nuxt"
    },
    status: {
      type: Number,
      default: 500
    },
    statusText: {
      type: String,
      default: "Internal server error"
    },
    description: {
      type: String,
      default: "This page is temporarily unavailable."
    },
    refresh: {
      type: String,
      default: "Refresh this page"
    }
  },
  setup(__props) {
    const props = __props;
    useHead$1({
      title: `${props.status} - ${props.statusText} | ${props.appName}`,
      script: [{ innerHTML: `!function(){let e=document.createElement("link").relList;if(!(e&&e.supports&&e.supports("modulepreload"))){for(let e of document.querySelectorAll('link[rel="modulepreload"]'))r(e);new MutationObserver(e=>{for(let t of e)if("childList"===t.type)for(let e of t.addedNodes)"LINK"===e.tagName&&"modulepreload"===e.rel&&r(e)}).observe(document,{childList:!0,subtree:!0})}function r(e){if(e.ep)return;e.ep=!0;let r=function(e){let r={};return e.integrity&&(r.integrity=e.integrity),e.referrerPolicy&&(r.referrerPolicy=e.referrerPolicy),r.credentials="use-credentials"===e.crossOrigin?"include":"anonymous"===e.crossOrigin?"omit":"same-origin",r}(e);fetch(e.href,r)}}();` }],
      style: [{ innerHTML: `*,:after,:before{box-sizing:border-box;border-style:solid;border-width:0;border-color:var(--un-default-border-color,#e5e7eb)}:after,:before{--un-content:""}html{-webkit-text-size-adjust:100%;tab-size:4;font-feature-settings:normal;font-variation-settings:normal;-webkit-tap-highlight-color:transparent;font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji;line-height:1.5}body{line-height:inherit;margin:0}h1,h2{font-size:inherit;font-weight:inherit}h1,h2,p{margin:0}*,:after,:before{--un-rotate:0;--un-rotate-x:0;--un-rotate-y:0;--un-rotate-z:0;--un-scale-x:1;--un-scale-y:1;--un-scale-z:1;--un-skew-x:0;--un-skew-y:0;--un-translate-x:0;--un-translate-y:0;--un-translate-z:0;--un-pan-x: ;--un-pan-y: ;--un-pinch-zoom: ;--un-scroll-snap-strictness:proximity;--un-ordinal: ;--un-slashed-zero: ;--un-numeric-figure: ;--un-numeric-spacing: ;--un-numeric-fraction: ;--un-border-spacing-x:0;--un-border-spacing-y:0;--un-ring-offset-shadow:0 0 #0000;--un-ring-shadow:0 0 #0000;--un-shadow-inset: ;--un-shadow:0 0 #0000;--un-ring-inset: ;--un-ring-offset-width:0px;--un-ring-offset-color:#fff;--un-ring-width:0px;--un-ring-color:#93c5fd80;--un-blur: ;--un-brightness: ;--un-contrast: ;--un-drop-shadow: ;--un-grayscale: ;--un-hue-rotate: ;--un-invert: ;--un-saturate: ;--un-sepia: ;--un-backdrop-blur: ;--un-backdrop-brightness: ;--un-backdrop-contrast: ;--un-backdrop-grayscale: ;--un-backdrop-hue-rotate: ;--un-backdrop-invert: ;--un-backdrop-opacity: ;--un-backdrop-saturate: ;--un-backdrop-sepia: }` }]
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "antialiased bg-white dark:bg-[#020420] dark:text-white font-sans grid min-h-screen overflow-hidden place-content-center text-[#020420] tracking-wide" }, _attrs))} data-v-bda08851><div class="max-w-520px text-center" data-v-bda08851><h1 class="font-semibold leading-none mb-4 sm:text-[110px] tabular-nums text-[80px]" data-v-bda08851>${ssrInterpolate(__props.status)}</h1><h2 class="font-semibold mb-2 sm:text-3xl text-2xl" data-v-bda08851>${ssrInterpolate(__props.statusText)}</h2><p class="mb-4 px-2 text-[#64748B] text-md" data-v-bda08851>${ssrInterpolate(__props.description)}</p></div></div>`);
    };
  }
};
var _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("../../node_modules/nuxt/dist/app/components/error-500.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
var error_500_default = /* @__PURE__ */ _plugin_vue_export_helper_default(_sfc_main, [["__scopeId", "data-v-bda08851"]]);

export { error_500_default as default };
//# sourceMappingURL=error-500-BEXyM9vI.mjs.map
