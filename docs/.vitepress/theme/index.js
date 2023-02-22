import DefaultTheme from 'vitepress/theme'
import NotFound from './components/NotFound.vue'
import "./styles.css";

export default {
  ...DefaultTheme,
  NotFound,
  enhanceApp(ctx) {
    // ctx.app.component('NotFound', NotFound)
    DefaultTheme.enhanceApp(ctx)
  }
}
