import { describe, it, expect, vi, beforeEach } from "vitest";

vi.mock("../../assets/src/bulk-tokenize.js", () => ({
  tokenizeBulk: vi.fn(),
  buildStatusPanel: vi.fn(),
  summaryLine: (minted, failed) => `${minted} minted, ${failed} failed.`,
  confirmMessage: (count) => `Tokenize ${count}?`,
}));

const bulk = await import("../../assets/src/bulk-tokenize.js");
await import("../../assets/src/tokenize-page.js");

const status = { element: document.createElement("div"), update: vi.fn(), finish: vi.fn() };
const flush = () => new Promise((resolve) => setTimeout(resolve));

function page() {
  document.body.innerHTML = `
    <div id="tokenize-content-page-status"></div>
    <button id="tokenize-content-page-run">Run</button>
    <input type="checkbox" id="tokenize-content-page-all" />
    <table><tbody id="tokenize-content-page-rows">
      <tr><td><input type="checkbox" value="1" data-title="One" /></td></tr>
      <tr><td><input type="checkbox" value="2" data-title="Two" /></td></tr>
      <tr><td><input type="checkbox" value="3" data-title="Three" disabled /></td></tr>
    </tbody></table>`;
  document.dispatchEvent(new Event("DOMContentLoaded"));
}

beforeEach(() => {
  vi.resetAllMocks();
  bulk.buildStatusPanel.mockReturnValue(status);
  vi.stubGlobal("confirm", vi.fn(() => true));
  page();
});

describe("the Tokenize page", () => {
  it("selects every row that can be tokenized with the header checkbox, and leaves disabled rows alone", () => {
    const all = document.getElementById("tokenize-content-page-all");
    all.checked = true;
    all.dispatchEvent(new Event("change"));

    const checked = Array.from(document.querySelectorAll("#tokenize-content-page-rows input")).map((box) => box.checked);
    expect(checked).toEqual([true, true, false]);
  });

  it("does nothing when no row is selected", () => {
    document.getElementById("tokenize-content-page-run").click();

    expect(confirm).not.toHaveBeenCalled();
    expect(bulk.tokenizeBulk).not.toHaveBeenCalled();
  });

  it("does not start when the confirmation is declined", () => {
    document.querySelector("#tokenize-content-page-rows input").checked = true;
    confirm.mockReturnValue(false);

    document.getElementById("tokenize-content-page-run").click();

    expect(bulk.tokenizeBulk).not.toHaveBeenCalled();
  });

  it("tokenizes the selected rows with their titles and shows the progress panel", async () => {
    document.querySelectorAll("#tokenize-content-page-rows input")[0].checked = true;
    document.querySelectorAll("#tokenize-content-page-rows input")[1].checked = true;
    bulk.tokenizeBulk.mockResolvedValue({ minted: 2, failed: 0 });
    const reload = vi.fn();
    vi.stubGlobal("location", { reload });

    document.getElementById("tokenize-content-page-run").click();
    await flush();

    expect(bulk.buildStatusPanel).toHaveBeenCalledWith(["1", "2"], { 1: "One", 2: "Two" });
    expect(document.getElementById("tokenize-content-page-status").contains(status.element)).toBe(true);
    expect(bulk.tokenizeBulk).toHaveBeenCalledWith(["1", "2"], expect.any(Function));
    expect(reload).toHaveBeenCalled();
  });

  it("keeps the page and shows the summary when some posts failed", async () => {
    document.querySelector("#tokenize-content-page-rows input").checked = true;
    bulk.tokenizeBulk.mockResolvedValue({ minted: 0, failed: 1 });
    const reload = vi.fn();
    vi.stubGlobal("location", { reload });

    document.getElementById("tokenize-content-page-run").click();
    await flush();

    expect(reload).not.toHaveBeenCalled();
    expect(status.finish).toHaveBeenCalledWith("0 minted, 1 failed.");
    expect(document.getElementById("tokenize-content-page-run").disabled).toBe(false);
  });

  it("shows the reason when the run cannot start", async () => {
    document.querySelector("#tokenize-content-page-rows input").checked = true;
    bulk.tokenizeBulk.mockRejectedValue(new Error("Not enough credits"));

    document.getElementById("tokenize-content-page-run").click();
    await flush();

    expect(status.finish).toHaveBeenCalledWith("Not enough credits");
    expect(document.getElementById("tokenize-content-page-run").disabled).toBe(false);
  });
});
