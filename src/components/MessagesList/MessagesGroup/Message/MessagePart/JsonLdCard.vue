<template>
    <div class="schema">
        <type-renderer ref="rendererEl" :data="jsonld" :current-user="sampleCurrentUser" />

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
            return '/index.php/apps/mail/compose?uri=mailto&json64='
                + encodeURIComponent(this.jsonldBase64)
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
            window.open(this.composeUrl, '_blank', 'noopener,noreferrer')
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
