export const __ = (text) => text;
export const _n = (single, plural, number) => (number === 1 ? single : plural);
export function sprintf(format, ...args) {
  let next = 0;
  return format.replace(/%(?:(\d+)\$)?[sd]/g, (_match, position) => String(args[position ? Number(position) - 1 : next++]));
}
