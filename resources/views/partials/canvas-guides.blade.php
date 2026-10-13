<svg class="canvas-snap-guides" aria-hidden="true" x-cloak x-show="snapGuides.length"
    :viewBox="'0 0 '+pageWidth+' '+pageHeight" preserveAspectRatio="none">
    <line x-show="snapGuides.some(guide => guide.axis === 'x')"
        :x1="snapGuides.find(guide => guide.axis === 'x')?.position ?? 0" y1="0"
        :x2="snapGuides.find(guide => guide.axis === 'x')?.position ?? 0" :y2="pageHeight" />
    <line x-show="snapGuides.some(guide => guide.axis === 'y')" x1="0"
        :y1="snapGuides.find(guide => guide.axis === 'y')?.position ?? 0" :x2="pageWidth"
        :y2="snapGuides.find(guide => guide.axis === 'y')?.position ?? 0" />
</svg>
