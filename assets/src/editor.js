export function hasUnsavedChanges() {
  const wp = window.wp;
  let store;
  try {
    store = wp?.data?.select?.("core/editor");
  } catch {
    store = undefined;
  }
  if (store?.isEditedPostDirty) return Boolean(store.isEditedPostDirty());
  return Boolean(wp?.autosave?.server?.postChanged?.());
}
