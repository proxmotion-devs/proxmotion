<template>
    <!-- The first stop for keyboards: past the menu to the page itself. Above the viewport until focused. -->
    <a
        href="#main-content"
        class="fixed top-3 left-3 z-50 -translate-y-[calc(100%+1rem)] rounded-md border bg-background px-3 py-2 text-sm font-medium text-foreground outline-none focus:translate-y-0 focus-visible:ring-[3px] focus-visible:ring-ring/50"
        data-test="skip-link"
        >{{ $t('Skip to content') }}</a
    >
</template>
