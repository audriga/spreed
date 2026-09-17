<template>
	<div class="no-localhost-reference-widget">
		<div class="debug-info">
			✓ Custom Widget Loaded ({{ richObject.title }})
		</div>
		<!-- <poll-editor ref="rendererEl" :data="jsonldData" :current-user="sampleCurrentUser" /> -->
        <type-renderer ref="rendererEl" :data="jsonldData"
        :current-user="sampleCurrentUser"
        />


		<div class="action-bar">
			<NcActions :force-name="true" :inline="1">
				<NcActionButton :aria-label="'Share by mail'" @click.prevent="onShareByMail">
					<template #icon>
						<EmailIcon :size="20" />
					</template>
					Share by mail
				</NcActionButton>
			</NcActions>
		</div>
	</div>
</template>

<script>


import 'json-ld-web-components'
import { NcActionButton, NcActions } from '@nextcloud/vue'
import EmailIcon from 'vue-material-design-icons/Email.vue'


export default {
	name: 'JsonLdCardReference',
	components: {
		NcActions,
		NcActionButton,
		EmailIcon,
	},
	props: {
		richObjectType: {
			type: String,
			required: true,
		},
		richObject: {
			type: Object,
			required: true,
		},
		accessible: {
			type: Boolean,
			default: true,
		},
	},
	computed: {
		// Map richObject.jsonld for use in template
		jsonldData() {
			console.debug('[JsonLdCardReference] jsonldData computed from richObject:', this.richObject)
			return this.richObject.jsonld || '{}'
		},

		sampleCurrentUser() {
			return 'simplemail@mailssimple.com'
		},

		jsonldBase64() {
			// btoa() only handles latin1, so UTF-8 encode the payload first
			const bytes = new TextEncoder().encode(this.jsonldData)
			let binary = ''
			for (const byte of bytes) {
				binary += String.fromCharCode(byte)
			}
			return btoa(binary)
		},

		// The mail app's compose endpoint was patched to accept json64
		composeUrl() {
			return '/index.php/apps/mail/compose?uri=mailto&json64='
				+ encodeURIComponent(this.jsonldBase64)
		},
	},
	mounted() {
		console.debug('[JsonLdCardReference] MOUNTED!')
		console.debug('[JsonLdCardReference] richObject:', this.richObject)
		console.debug('[JsonLdCardReference] accessible:', this.accessible)
		console.debug('[JsonLdCardReference] richObjectType:', this.richObjectType)

		// renderer-error is the standardized, host-facing error CustomEvent
		// every JsonLdElement-based renderer dispatches (bubbles + composed),
		// regardless of whether the failure was a fetch/parse/JWT error in
		this.$refs.rendererEl.addEventListener('renderer-error', (e) => {
			console.error('[JsonLdCardReference] renderer-error:', e.detail.message, e.detail.error)
		})
	},
	methods: {
		onShareByMail() {
			window.open(this.composeUrl, '_blank', 'noopener,noreferrer')
		},
	},
}
</script>

<style scoped>
.localhost-reference-widget {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.debug-info {
	background-color: #c3e6cb;
	color: #155724;
	padding: 8px 12px;
	border-radius: 4px;
	margin-bottom: 8px;
	font-weight: bold;
	font-size: 12px;
}

.action-bar {
	display: flex;
	justify-content: flex-start;
	padding-top: 10px;
}
</style>


