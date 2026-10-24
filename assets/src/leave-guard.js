export function guardLeaving() {
  const handler = (event) => {
    event.preventDefault();
    event.returnValue = "";
  };
  window.addEventListener("beforeunload", handler);
  return () => window.removeEventListener("beforeunload", handler);
}
