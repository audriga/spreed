<!--
  - SPDX-FileCopyrightText: 2025
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="schema">
		<div v-html="html"></div>
		
	<div class="action-bar">
		<NcActions v-if="shouldRenderButtons" :force-name="true" :secondary="true" :inline="1">
			<NcActionButton :aria-label="'Confirm'" @click.prevent="onConfirm" secondary>
				<template #icon>
				<CheckIcon :size="20" />
				</template>
				Confirm
			</NcActionButton>
			<NcActionButton :aria-label="'Cancel'" @click.prevent="onCancel" secondary>
				<template #icon>
				<CloseIcon :size="20" />
				</template>
				Cancel
			</NcActionButton>
		</NcActions>
	</div>

	</div>
</template>

<script>

import Jsonld2html from 'jsonld2html-cards'
import { NcActionButton, NcActions, NcLoadingIcon } from '@nextcloud/vue'
import SilverwareForkKnifeIcon from 'vue-material-design-icons/SilverwareForkKnife.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarker.vue'
import MapSearchOutlineIcon from 'vue-material-design-icons/MapSearchOutline.vue'

export default {
	name: 'JsonLdCard', // still called DeckCard for substitution
	props:{
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
		}
	},
	data() {
		return {
			html: "",
			// TODO check if there is a case where jsonld could be an object!
			jsonString: this.jsonld,

		}
	},
	computed: {

		jsonLdParsed() {
			return JSON.parse(this.jsonString)
		},

		shouldRenderButtons() {

			const jsonObject = JSON.parse(this.jsonString);
			if(jsonObject["@type"] === "Message"){
				return true
			}
			else {
				return false
			}
		},

		cancelLink(){
			// TODO fix this !
			return this.jsonLdParsed["potentialAction"][0]["target"]
		},
		confirmLink(){
			// TODO fix this!
			return this.jsonLdParsed["potentialAction"][1]["target"]
		}
	},
	created() {
		this.getRenderedSchema()
	},
	methods: {
		getRenderedSchema() {
			//TODO add computed
			const tempJsonObject = JSON.parse(this.jsonString)

			// need to remove, otherwise will render buttons as well
			delete tempJsonObject["potentialAction"]

			const rendered = Jsonld2html.render(tempJsonObject, false)
			this.html = rendered
			return rendered
		},
		async onConfirm() {
			const url = this.confirmLink
			console.log(url)
			try{
				const response = await fetch(url);
				if (!response.ok) {
					throw new Error(`Response status: ${response.status}`);
				}

				const result = await response.text();
				console.log(result);
			} catch (error) {
				console.error(error.message);
			}

		},
		async onCancel() {
			const url = this.cancelLink
			console.log(url)
						try{
				const response = await fetch(url);
				if (!response.ok) {
					throw new Error(`Response status: ${response.status}`);
				}

				const result = await response.text();
				console.log(result);
			} catch (error) {
				console.error(error.message);
			}
		}
		
	}
}
</script>


<style scoped>
 .schema { /* Box surrounding the displayed card and possible actions. */ display: flex; width: fit-content; flex-direction: column; margin: 50px; border: 2px none var(--color-border); border-radius: 16px; padding: 10px; align-items: left; box-shadow: 0px 0px 10px 0px var(--color-box-shadow); } .full-schema { /* Useful for debugging the component. */ display: none; font-size: x-small; opacity: 0.4; font-weight: lighter; } /* Fix using Vue 3 deep combinator ::v-deep */ .schema ::v-deep(.smlCard) { max-width: 600px; display: flex; flex-direction: column; gap: 5px; /* round corners*/ border: 2px solid var(--color-border); border-radius: 6px; /* padding in the card*/ padding: 20px; background: var(--color-main-background); } .schema ::v-deep(.smlCard .header) { /* create a bottom border from left to right */ border-block-end: 2px solid var(--color-border); margin: -10px -20px 0px -20px; padding-bottom: 10px; /* add spacing before text */ text-indent: 20px; font-size: 20px; font-weight: bold; color: var(--color-main-text); } .schema ::v-deep(.smlCardRow) { /* Layout Settings */ display: flex; flex-direction: row; flex-wrap: nowrap; justify-content: flex-start; } .schema ::v-deep(.smlCardRow .text_column) { display: flex; flex-direction: column; flex-wrap: nowrap; justify-content: flex-start; align-items: flex-start; min-height: 100px; max-height: 150px; flex-basis: 90%; min-width: 0; margin-left: 20px; } .schema ::v-deep(.smlCardRow .card_title) { margin: 4px 0px; font-size: 20px; font-weight: bold; min-height: 20%; color: var(--color-main-text); /* settings for truncating single line text */ max-width: 95%; text-overflow: ellipsis; white-space: nowrap; overflow-x: auto; } .schema ::v-deep(.smlCardRow .card_content) { margin-top: 4px; margin-bottom: 4px; font-size: 16px; color: var(--color-main-text); /* this is for truncating multiline texts */ display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 4; overflow: auto; } .schema ::v-deep(.smlCardRow .image_column) { display: flex; align-items: center; justify-content: center; min-height: 100px; min-width: 100px; max-width: 100px; overflow: hidden; } .schema ::v-deep(.smlCardRow img) { display: block; max-width: 100px; max-height: 100px; min-width: 100px; } .schema ::v-deep(br) { display: none; }

.action-bar {
    display: flex;
    justify-content: flex-end;

    padding-top: 10px;
}

.action-bar:empty {
    display: none;
}

</style>

async function performGetRequest(url) {
  
  try {
    const response = await fetch(url);
    if (!response.ok) {
      throw new Error(`Response status: ${response.status}`);
    }

    const result = await response.text();
    console.log(result);
  } catch (error) {
    console.error(error.message);
  }
}
