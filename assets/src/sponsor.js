import { buildSponsoredInvoke, executeSponsoredInvoke } from "./api.js";
import { ensureSession, forgetSession } from "./session.js";
import { signTypedData } from "./wallet.js";

const SIGN_IN_REQUIRED = "invalid_request_auth";

export async function sponsor(account, address, intentIds, posts) {
  const attempt = async () => {
    await ensureSession(account, address);
    const built = await buildSponsoredInvoke({ intentIds });
    const signature = await signTypedData(account, built.typedData);
    return executeSponsoredInvoke({ intentIds, typedData: built.typedData, signature, posts });
  };
  try {
    return await attempt();
  } catch (err) {
    if (err.code !== SIGN_IN_REQUIRED) throw err;
    forgetSession();
    return attempt();
  }
}
