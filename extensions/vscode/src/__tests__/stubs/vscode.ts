// Minimal `vscode` stub for vitest. The real module is only available at
// runtime inside VSCode; tests should not exercise UI surfaces directly.
export const window = {
  showErrorMessage: (_message: string) => undefined,
  showInformationMessage: (_message: string) => undefined,
};

export const Uri = {
  parse: (value: string) => ({ toString: () => value }),
};

export interface UriHandler {
  handleUri(uri: unknown): unknown;
}
