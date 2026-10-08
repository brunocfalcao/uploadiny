export function createWorkspaceOperations() {
    return {
        saving: false, navigating: false, transferring: false, loading: false,
        get editorBusy() { return this.saving || this.navigating || this.transferring; },
        get busy() { return this.editorBusy || this.loading; },
    };
}
