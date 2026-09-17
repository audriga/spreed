import { t, n } from '@nextcloud/l10n'
import { createApp } from 'vue'
import { registerWidget } from '@nextcloud/vue/functions/registerReference'
import JsonLdCardReference from './JsonLdCardReference.vue'

console.debug('[widget-reference.js] Loading...')

// NOTE: this package is Vue 3 ("vue": "^3.5.17" in package.json, see main.js
// using createApp everywhere) - `import Vue from 'vue'` + `Vue.mixin`/`Vue.extend`
// are Vue 2 APIs and don't exist here. `vue`'s default export is undefined under
// Vue 3, so `Vue.mixin(...)` used to throw a TypeError synchronously and kill this
// whole script before registerWidget() below ever ran.

console.debug('[widget-reference.js] About to register widget for type: spreed-localhost-preview')

const mountedApps = new WeakMap()

// Register the widget component to handle 'spreed-localhost-preview' rich objects
registerWidget('spreed-localhost-preview', (el, { richObjectType, richObject, accessible }) => {
	console.debug('[widget-reference] registerWidget callback fired!')
	console.debug('[widget-reference] richObjectType:', richObjectType)
	console.debug('[widget-reference] richObject:', richObject)
	console.debug('[widget-reference] accessible:', accessible)

	const app = createApp(JsonLdCardReference, {
		richObjectType,
		richObject,
		accessible,
	})
	app.mixin({ methods: { t, n } })
	app.mount(el)
	mountedApps.set(el, app)
	console.debug('[widget-reference] Widget mounted to element:', el)
}, (el) => {
	console.debug('[widget-reference] Widget unmount called')
	const app = mountedApps.get(el)
	if (app) {
		app.unmount()
		mountedApps.delete(el)
	}
}, {
	// Without this, registerWidget() defaults hasInteractiveView to true,
	// which makes NcReferenceWidget gate rendering behind an "Enable
	hasInteractiveView: false,
})

console.debug('[widget-reference.js] Widget registration complete')




