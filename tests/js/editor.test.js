import { describe, it, expect, afterEach } from "vitest";
import { hasUnsavedChanges } from "../../assets/src/editor.js";

afterEach(() => {
  delete window.wp;
});

describe("hasUnsavedChanges", () => {
  it("is true when the block editor has edits that are not saved", () => {
    window.wp = { data: { select: () => ({ isEditedPostDirty: () => true }) } };
    expect(hasUnsavedChanges()).toBe(true);
  });

  it("is false when the block editor is saved", () => {
    window.wp = { data: { select: () => ({ isEditedPostDirty: () => false }) } };
    expect(hasUnsavedChanges()).toBe(false);
  });

  it("asks the classic editor when there is no block editor store", () => {
    window.wp = { data: { select: () => undefined }, autosave: { server: { postChanged: () => true } } };
    expect(hasUnsavedChanges()).toBe(true);
    window.wp = { autosave: { server: { postChanged: () => false } } };
    expect(hasUnsavedChanges()).toBe(false);
  });

  it("asks the classic editor when reading the block editor store fails", () => {
    window.wp = { data: { select: () => { throw new Error("no store"); } }, autosave: { server: { postChanged: () => true } } };
    expect(hasUnsavedChanges()).toBe(true);
  });

  it("is false when there is no editor to ask", () => {
    expect(hasUnsavedChanges()).toBe(false);
  });
});
