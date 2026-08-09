import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const advisory = "GHSA-qwww-vcr4-c8h2";
const packageJson = JSON.parse(
  fs.readFileSync(new URL("../package.json", import.meta.url), "utf8"),
);
const packageLock = JSON.parse(
  fs.readFileSync(new URL("../package-lock.json", import.meta.url), "utf8"),
);
const declared = packageJson.dependencies["react-router-dom"];
const installed =
  packageLock.packages?.["node_modules/react-router-dom"]?.version;
if (typeof installed !== "string") {
  throw new Error("Unable to resolve the installed react-router-dom version.");
}
const latest = execFileSync("npm", ["view", "react-router-dom", "version"], {
  encoding: "utf8",
}).trim();

function versionParts(version) {
  return version
    .replace(/^[^0-9]*/, "")
    .split(".")
    .slice(0, 3)
    .map(Number);
}

function atLeast(version, target) {
  const parts = versionParts(version);
  for (let index = 0; index < target.length; index += 1) {
    if ((parts[index] ?? 0) > target[index]) return true;
    if ((parts[index] ?? 0) < target[index]) return false;
  }
  return true;
}

const forbiddenRscApis =
  /\b(?:RSCHydratedRouter|RSCStaticRouter|createCallServer|getRSCStream|matchRSCServerRequest|routeRSCServerRequest)\b/;
const sourceRoot = fileURLToPath(new URL("../src/", import.meta.url));
const rscUsages = [];
function scan(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) scan(file);
    else if (/\.[jt]sx?$/.test(entry.name)) {
      const source = fs.readFileSync(file, "utf8");
      if (forbiddenRscApis.test(source))
        rscUsages.push(path.relative(sourceRoot, file));
    }
  }
}
scan(sourceRoot);

if (rscUsages.length) {
  throw new Error(
    `${advisory}: unstable React Router RSC APIs detected in ${rscUsages.join(", ")}`,
  );
}
const installedMajor = versionParts(installed)[0];
const patchedVersion = installedMajor === 7 ? [7, 18, 2] : [8, 3, 0];
if (!atLeast(installed, patchedVersion)) {
  throw new Error(
    `${advisory}: installed react-router-dom ${installed} (${declared}) is below the patched ${patchedVersion.join(".")} release; registry latest is ${latest}.`,
  );
}

console.log(
  `${advisory}: RSC APIs absent; installed ${installed} (${declared}) meets patched ${patchedVersion.join(".")} requirement; registry latest ${latest}.`,
);
