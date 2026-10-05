import { createApp, h, type DefineComponent } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import "@fontsource/outfit/400.css";
import "@fontsource/outfit/500.css";
import "@fontsource/outfit/600.css";
import "@fontsource/outfit/700.css";
import "@fontsource/geist-sans/400.css";
import "@fontsource/geist-sans/500.css";
import "@fontsource/geist-sans/600.css";
const pages = import.meta.glob<{ default: DefineComponent }>(
    "./Pages/**/*.vue",
);
createInertiaApp({
    title: (title) => `${title} · Ruang Periksa`,
    resolve: async (name) => (await pages[`./Pages/${name}.vue`]()).default,
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: { color: "#3d6662" },
});
