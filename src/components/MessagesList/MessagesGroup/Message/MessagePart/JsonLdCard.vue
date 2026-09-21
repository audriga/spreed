<template>
    <div class="schema">
        <type-renderer ref="rendererEl" :data="jsonld" :current-user="sampleCurrentUser" />

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
    name: 'JsonLdCard',
    components: {
        NcActions,
        NcActionButton,
        EmailIcon,
    },
    props: {
        name: {
            type: String,
            required: true,
        },
        description: {
            type: String,
            required: true,
        },
        jsonld: {
            type: String,
            required: true,
        },
    },
    computed: {

        jsonldObject() {
            try {
                return JSON.parse(this.jsonld)
            } catch (error) {
                console.error('[JsonLdCard] Failed to parse jsonld as JSON:', error, this.jsonld)
                return {}
            }
        },

        isRecipe() {
            const type = this.jsonldObject['@type']
            if (Array.isArray(type)) {
                return type.includes('Recipe')
            }
            return type === 'Recipe'
        },

        // See the <type-renderer> comment in <template> above — illustrative
        // hardcoded value, standing in for whatever real user identifier
        // (e.g. logged-in user's email) your host would actually pass.
        // the value is used by the poll-renderer in such a case
        sampleCurrentUser() {
            return 'simplemail@mailssimple.com'
        },

        jsonldBase64() {
            // btoa() only handles latin1, so UTF-8 encode the payload first
            const bytes = new TextEncoder().encode(this.jsonld)
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
        // every JsonLdElement-based renderer dispatches (bubbles + composed),
        // regardless of whether the failure was a fetch/parse/JWT error in
        this.$refs.rendererEl.addEventListener('renderer-error', (e) => {
            console.error(e.detail.message, e.detail.error)
        })
    },
    methods: {
        onShareByMail() {
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

                console.debug('[JsonLdCard] Recipe added to cookbook successfully')
            } catch (error) {
                console.error('[JsonLdCard] Failed to add recipe to cookbook:', error)
            }
        },
    },
}
</script>

<style>
.action-bar {
    display: flex;
    justify-content: flex-start;
    padding-top: 10px;
}
</style>
