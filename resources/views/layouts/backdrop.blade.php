<div x-data x-cloak
    :class="$store.sidebar.isMobileOpen ? 'block xl:hidden' : 'hidden'"
    @click="$store.sidebar.setMobileOpen(false)"
    class="fixed z-50 hidden h-screen w-full bg-gray-900/50"></div>
