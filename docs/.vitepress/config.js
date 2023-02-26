import { createWriteStream } from 'node:fs'
import { resolve } from 'node:path'
import { SitemapStream } from 'sitemap'

import { createRequire } from 'module'
import { defineConfig } from 'vitepress'

const links = []

const require = createRequire(import.meta.url)
const pkg = require('../../package.json')

export default defineConfig({
  base: '',
  lang: 'ru-RU',
  title: 'FetchIt',
  description: 'Компонент MODX Revolution для отправки и обработки форм с помощью Fetch API',

  lastUpdated: true,
  cleanUrls: true,

  head: [
    ['link', { rel: 'apple-touch-icon', sizes: '180x180', href: '/apple-touch-icon.png' }],
    ['link', { rel: 'icon', type: 'image/png', sizes: '32x32', href: '/favicon-32x32.png' }],
    ['link', { rel: 'icon', type: 'image/png', sizes: '16x16', href: '/favicon-16x16.png' }],
    ['link', { rel: 'manifest', href: '/site.webmanifest' }],
    ['meta', { name: 'msapplication-TileColor', content: '#2d89ef' }],
    ['meta', { name: 'theme-color', content: '#ffffff' }],
  ],

  markdown: {
    headers: {
      level: [0, 0],
    },
  },

  themeConfig: {
    nav: nav(),

    outlineTitle: 'На этой странице',
    returnToTopLabel: 'Наверх',
    sidebarMenuLabel: 'Меню',
    darkModeSwitchLabel: 'Тема',
    lastUpdatedText: 'Последнее обновление',

    sidebar: {
      '/guide/': sidebarGuide(),
      '/examples/': sidebarGuide(),
    },

    editLink: {
      pattern: 'https://github.com/GulomovCreative/FetchIt/edit/master/docs/:path',
      text: 'Редактировать эту страницу на GitHub'
    },

    socialLinks: [
      {
        icon: 'github',
        link: 'https://github.com/GulomovCreative/FetchIt',
      },
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2023'
    },

    docFooter: {
      prev: 'Предыдущая страница',
      next: 'Следующая страница'
    },
  },

  transformHtml: (_, id, { pageData }) => {
    if (!/[\\/]404\.html$/.test(id))
      links.push({
        url: pageData.relativePath.replace(/((^|\/)index)?\.md$/, '$2'),
        lastmod: pageData.lastUpdated
      })
  },

  buildEnd: async ({ outDir }) => {
    const sitemap = new SitemapStream({
      hostname: 'https://fetchit.codesolution.io/'
    })
    const writeStream = createWriteStream(resolve(outDir, 'sitemap.xml'))
    sitemap.pipe(writeStream)
    links.forEach((link) => sitemap.write(link))
    sitemap.end()
    await new Promise((r) => writeStream.on('finish', r))
  }
})

function nav() {
  return [
    { text: 'Документация', link: '/guide/introduction', activeMatch: '/guide/' },
    { text: 'Примеры', link: '/examples/', activeMatch: '/examples/' },
    {
      text: pkg.version,
      items: [
        {
          text: 'История изменений',
          link: 'https://github.com/GulomovCreative/FetchIt/blob/master/core/components/fetchit/docs/changelog.txt'
        }
      ]
    }
  ]
}

function sidebarGuide() {
  return [
    {
      text: 'Основы',
      collapsed: false,
      items: [
        { text: 'Введение', link: '/guide/introduction' },
        { text: 'Установка', link: '/guide/installation' },
      ]
    },
    {
      text: 'Использование',
      collapsed: false,
      items: [
        { text: 'Быстрый старт', link: '/guide/quick-start' },
        { text: 'Сниппет FetchIt', link: '/guide/snippets/fetchit' },
        { text: 'Настройки компонента', link: '/guide/settings' },
        { text: 'Селекторы', link: '/guide/selectors' },
        { text: 'Миграция с AjaxForm', link: '/guide/migration-from-ajaxform' },
        { text: 'Обработка своим сниппетом', link: '/guide/snippets/custom' },
      ]
    },
    {
      text: 'JS API',
      collapsed: false,
      items: [
        { text: 'Класс FetchIt', link: '/guide/frontend/class' },
        { text: 'Экземпляр класса FetchIt', link: '/guide/frontend/instance' },
        { text: 'События', link: '/guide/frontend/events' },
      ]
    },
    {
      text: 'Разметка форм',
      collapsed: true,
      items: [
        { text: 'Bootstrap', link: '/examples/form/bootstrap' },
        { text: 'Bulma', link: '/examples/form/bulma' },
        { text: 'UIkit', link: '/examples/form/uikit' },
        { text: 'Fomantic-UI', link: '/examples/form/fomantic' },
        { text: 'Pico.css', link: '/examples/form/pico' },
        { text: 'Cirrus CSS', link: '/examples/form/cirrus' },
        { text: 'turretcss', link: '/examples/form/turretcss' },
        { text: 'Vanilla', link: '/examples/form/vanilla' },
      ]
    },
    {
      text: 'Всплывающие сообщения',
      collapsed: true,
      items: [
        { text: 'SweetAlert2', link: '/examples/notifications/sweetalert2' },
        { text: 'Notyf', link: '/examples/notifications/notyf' },
        { text: 'iziToast', link: '/examples/notifications/izitoast' },
        { text: 'Notiflix.Notify', link: '/examples/notifications/notiflix-notify' },
        { text: 'Notie', link: '/examples/notifications/notie' },
        { text: 'Awesome Notifications', link: '/examples/notifications/awesome-notifications' },
        { text: 'Toastify JS', link: '/examples/notifications/toastifyjs' },
        { text: 'AlertifyJS', link: '/examples/notifications/alertifyjs' },
        { text: 'PNotify', link: '/examples/notifications/pnotify' },
        { text: 'toastr', link: '/examples/notifications/toastr' },
        { text: 'jGrowl', link: '/examples/notifications/jgrowl' },
        { text: 'NOTY', link: '/examples/notifications/noty' },
      ]
    },
    {
      text: 'Модальные окна',
      collapsed: true,
      items: [
        { text: 'Bootstrap', link: '/examples/modals/bootstrap' },
        { text: 'tingle.js', link: '/examples/modals/tinglejs' },
        { text: 'Micromodal.js', link: '/examples/modals/micromodaljs' },
      ]
    },
  ]
}
