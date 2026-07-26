import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const advisory = "GHSA-qwww-vcr4-c8h2";
const patchedVersion = [8, 3, 0];
const packageJson = JSON.parse(
  fs.readFileSync(new URL("../package.json", import.meta.url), "utf8"),
);
const installed = packageJson.dependencies["react-router-dom"];
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
if (atLeast(latest, patchedVersion) && !atLeast(installed, patchedVersion)) {
  throw new Error(
    `${advisory}: react-router-dom ${latest} is now published; upgrade from ${installed} and remove the temporary Trivy exception.`,
  );
}

console.log(
  `${advisory}: RSC APIs absent; installed ${installed}; registry latest ${latest}; patched 8.3.0 not yet available.`,
);
