<template>
    <main class="auth">
        <div class="ticket">
            <div class="side"><slot name="stub" /></div>
            <div class="form">
                <p v-if="$page.props.flash.success" role="status">{{ $page.props.flash.success }}</p>
                <p v-if="$page.props.flash.error" class="error" role="status">{{ $page.props.flash.error }}</p>
                <slot />
            </div>
        </div>
    </main>
</template>

<style scoped>
.auth { min-height: calc(100vh - 94px); display: grid; place-items: center; padding: 12px 24px 56px; }
.ticket {
    width: min(1060px, 100%);
    display: grid;
    grid-template-columns: 400px minmax(0, 1fr);
    filter: drop-shadow(0 28px 50px #0007);
}
.side {
    background: var(--color-primary);
    padding: 42px 38px;
    border-radius: 12px 0 0 12px;
    display: flex;
    flex-direction: column;
    min-height: 560px;
    min-width: 0;
}
.side :slotted(h1) { font-weight: 800; font-stretch: 75%; font-size: 3.3rem; line-height: .96; }
.side :slotted(p) { margin-top: 14px; color: #e3ebff; }
.form {
    background: #fff;
    color: var(--color-ink);
    padding: 42px 46px;
    border-radius: 0 12px 12px 0;
    border-left: 3px dashed #9db4e0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 18px;
}
.form :slotted(form) { display: flex; flex-direction: column; gap: 18px; }
.form :slotted(.alt) { color: var(--color-muted); }
.form :slotted(.alt a) { color: var(--color-primary); font-weight: 600; text-decoration: underline; }
.form :slotted(a:focus-visible), .form :slotted(.alt a:focus-visible) { outline-color: var(--color-primary); }
.error { color: var(--color-danger); }
@media (max-width: 900px) {
    .ticket { grid-template-columns: minmax(0, 1fr); }
    .side { min-height: 0; border-radius: 12px 12px 0 0; padding: 28px 24px; gap: 22px; }
    .side :slotted(h1) { font-size: 2.5rem; }
    .form { border-left: 0; border-top: 3px dashed #9db4e0; border-radius: 0 0 12px 12px; padding: 28px 24px; }
}
</style>
