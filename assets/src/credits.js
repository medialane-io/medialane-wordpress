import { __ } from "@wordpress/i18n";
import { getAccount } from "./api.js";

export const noCredits = () => __("No credits left. Add credits at portal.medialane.io/account.", "tokenize-content");
export const keyRejected = () => __("The API key was not accepted. Check it at portal.medialane.io/account.", "tokenize-content");

export async function assertCredits() {
  let account;
  try {
    ({ data: account } = await getAccount());
  } catch (err) {
    if (err.status === 401) throw new Error(keyRejected());
    if (err.status === 402) throw new Error(noCredits());
    return;
  }
  if (Number(account?.creditBalance) <= 0) throw new Error(noCredits());
}
