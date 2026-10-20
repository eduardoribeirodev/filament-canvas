<div class="image-frame">
    <img class="image" x-show="data.image_url" :src="data.image_url || null" :style="data.image_style" :alt="data.media.image?.name || ''">
    <span class="missing" x-show="!data.image_url">Select an image</span>
</div>
