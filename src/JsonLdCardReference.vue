<template>
	<div class="no-localhost-reference-widget">
		<!-- <poll-editor ref="rendererEl" :data="jsonldData" :current-user="sampleCurrentUser" /> -->
        <type-renderer ref="rendererEl" :data="jsonldData"
        :current-user="sampleCurrentUser"
        />


		<div class="action-bar">
			<NcActions :force-name="true" :inline="2">
				<NcActionButton :aria-label="'Share by mail'" @click.prevent="onShareByMail">
					<template #icon>
						<EmailIcon :size="20" />
					</template>
					Share by mail
				</NcActionButton>
				<NcActionButton v-if="isRecipe" :aria-label="'Add recipe to cookbook'" @click.prevent="onAddToCookbook">
					Add recipe to cookbook
				</NcActionButton>
			</NcActions>
		</div>
	</div>
</template>

<script>


import 'json-ld-web-components'
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
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

		jsonldObject() {
			// richObject.jsonld might already be an object (not a JSON string),
			// depending on how the backend serialized the rich object parameters.
			if (typeof this.jsonldData === 'object' && this.jsonldData !== null) {
				console.debug('[JsonLdCardReference] jsonldData is already an object, using as-is:', this.jsonldData)
				return this.jsonldData
			}

			try {
				const parsed = JSON.parse(this.jsonldData)
				console.debug('[JsonLdCardReference] jsonldObject parsed successfully:', parsed)
				return parsed
			} catch (e) {
				console.error('[JsonLdCardReference] Failed to parse jsonldData as JSON:', e, this.jsonldData)
				return {}
			}
		},

		isRecipe() {
			const type = this.jsonldObject['@type']
			console.debug('[JsonLdCardReference] isRecipe check, @type:', type, 'jsonldObject:', this.jsonldObject)
			if (Array.isArray(type)) {
				return type.includes('Recipe')
			}
			return type === 'Recipe'
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
			return `${generateUrl('apps/mail/compose')}?uri=mailto&json64=`
				+ encodeURIComponent(this.jsonldBase64)
		},

		cookbookUrl() {
			return generateUrl('apps/cookbook/api/v1/recipes')
		},
	},
	mounted() {

		// renderer-error is the standardized, host-facing error CustomEvent
		this.$refs.rendererEl.addEventListener('renderer-error', (e) => {
			console.error('[JsonLdCardReference] renderer-error:', e.detail.message, e.detail.error)
		})
	},
	methods: {
		onShareByMail() {
			console.log(this.composeUrl)
			window.location.href = this.composeUrl
		},

		async onAddToCookbook() {
			try {
				const response = await fetch(this.cookbookUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
						requesttoken: getRequestToken(),
					},
					body: JSON.stringify(this.jsonldObject),
				})

				if (!response.ok) {
					throw new Error(`Cookbook API responded with status ${response.status}`)
				}

				console.debug('[JsonLdCardReference] Recipe added to cookbook successfully')
			} catch (error) {
				console.error('[JsonLdCardReference] Failed to add recipe to cookbook:', error)
			}
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


