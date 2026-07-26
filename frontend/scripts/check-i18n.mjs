import fs from "node:fs";
import path from "node:path";
import ts from "typescript";

const sourceRoot = path.resolve("src");
const catalogPath = path.join(sourceRoot, "i18n/translations.ts");
const catalogSource = ts.createSourceFile(
  catalogPath,
  fs.readFileSync(catalogPath, "utf8"),
  ts.ScriptTarget.Latest,
  true,
  ts.ScriptKind.TS,
);
const catalog = new Set();

function readCatalog(node) {
  if (
    ts.isVariableDeclaration(node) &&
    node.name.getText(catalogSource) === "pairs" &&
    node.initializer &&
    ts.isArrayLiteralExpression(node.initializer)
  ) {
    for (const pair of node.initializer.elements) {
      if (!ts.isArrayLiteralExpression(pair)) continue;
      for (const value of pair.elements) {
        if (ts.isStringLiteral(value)) catalog.add(value.text);
      }
    }
  }
  ts.forEachChild(node, readCatalog);
}
readCatalog(catalogSource);

const files = [];
function walk(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) walk(file);
    else if (file.endsWith(".tsx") && !file.endsWith(".test.tsx"))
      files.push(file);
  }
}
walk(sourceRoot);

const ignoredExact = new Set([
  "RiskPilot",
  "KPI",
  "KRI",
  "AIPD",
  "DPIA",
  "MTPD",
  "RTO (h)",
  "MTPD (h)",
  "SAML",
  "SCIM",
  "OIDC",
  "CSV",
  "Action",
  "Code",
  "Version",
  "IP",
  "URL",
  "URL HTTPS",
  "Microsoft 365 — OAuth 2.0",
  "SMTP2GO/SMTP ou connexion OAuth 2.0 Google Workspace et Microsoft 365.",
]);
const ignoredPattern =
  /^(?:[A-Z0-9_:-]+|[a-z][A-Za-z0-9]*|[/._#&]|https?:|[^ ]+@[^ ]+|(?:h[1-6]|body2|caption|small|large|medium|primary|secondary|default|error|info|success|warning|outlined|contained|scrollable|determinate|numeric|password|email|number|date|file|submit|button|form|table|row|column|grid|flex|block|fixed|temporary|permanent|inherit|initial|auto|none|center|right|start|stretch|wrap|transparent|white|anywhere|noreferrer|blob|current|gross|residual|calendar|kanban|timeline|oauth))$/;

function normalize(value) {
  return value.replace(/\s+/g, " ").trim();
}

function isIgnored(value) {
  return (
    ignoredExact.has(value) ||
    ignoredPattern.test(value) ||
    value.includes("ChangeMe123!") ||
    value.includes("riskpilot.local") ||
    value.includes("SHA-256")
  );
}

const missing = [];
function add(value, file, node) {
  value = normalize(value);
  if (
    value.length < 2 ||
    !/[A-Za-zÀ-ÿ]/.test(value) ||
    catalog.has(value) ||
    isIgnored(value)
  )
    return;
  const line = file.getLineAndCharacterOfPosition(node.getStart(file)).line + 1;
  missing.push(
    `${path.relative(process.cwd(), file.fileName)}:${line} ${JSON.stringify(value)}`,
  );
}

for (const filename of files) {
  const file = ts.createSourceFile(
    filename,
    fs.readFileSync(filename, "utf8"),
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  );
  function visit(node) {
    if (ts.isJsxText(node)) add(node.text, file, node);
    if (
      ts.isJsxAttribute(node) &&
      node.initializer &&
      ts.isStringLiteral(node.initializer) &&
      ["aria-label", "placeholder", "title"].includes(node.name.getText(file))
    ) {
      add(node.initializer.text, file, node.initializer);
    }
    if (
      ts.isPropertyAssignment(node) &&
      ts.isStringLiteral(node.initializer) &&
      ["label", "title", "description", "helperText"].includes(
        node.name.getText(file),
      )
    ) {
      add(node.initializer.text, file, node.initializer);
    }
    ts.forEachChild(node, visit);
  }
  visit(file);
}

if (missing.length) {
  console.error(
    `Missing FR/EN catalog entries (${missing.length}):\n${missing.join("\n")}`,
  );
  process.exit(1);
}
console.log(`FR/EN catalog audit passed (${catalog.size} localized values).`);
