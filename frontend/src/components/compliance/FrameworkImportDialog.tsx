import { useState } from "react";
import { Alert, Button, Dialog, DialogActions, DialogContent, DialogTitle, Stack, TextField, Typography } from "@mui/material";
import { isAxiosError } from "axios";
import { api } from "../../api/client";
import { useAuth } from "../../auth/useAuth";

type Preview = { checksum: string; count: number; requirements: { reference: string; title: string; parentReference: string; status?: string }[] };

export function FrameworkImportDialog({ open, onClose, onImported }: { open: boolean; onClose: () => void; onImported: () => void }) {
  const { user } = useAuth();
  const en = user?.locale === "en";
  const t = (fr: string, english: string) => en ? english : fr;
  const [name, setName] = useState("");
  const [version, setVersion] = useState("");
  const [csv, setCsv] = useState("");
  const [preview, setPreview] = useState<Preview | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [reading, setReading] = useState(false);
  const resetPreview = () => { setPreview(null); setError(""); };
  const submit = async () => {
    setBusy(true); setError("");
    try {
      const body = { name, version, csv, ...(preview ? { checksum: preview.checksum } : {}) };
      if (preview) {
        await api.post("/frameworks/import/confirm", body);
        setPreview(null); setCsv(""); setName(""); setVersion(""); onImported(); onClose();
      } else {
        setPreview((await api.post<Preview>("/frameworks/import/preview", body)).data);
      }
    } catch (cause) {
      setPreview(null);
      setError(isAxiosError(cause) && typeof cause.response?.data?.message === "string" ? cause.response.data.message : t("Import impossible. Vérifiez les champs et le fichier.", "Import failed. Check the fields and file."));
    } finally { setBusy(false); }
  };
  return <Dialog open={open} onClose={busy || reading ? undefined : onClose} fullWidth maxWidth="md">
    <DialogTitle>{t("Importer un référentiel CSV", "Import a CSV framework")}</DialogTitle>
    <DialogContent><Stack spacing={2} sx={{ pt: 1 }}>
      <Alert severity="info">{t("Crée une nouvelle version sans écraser les référentiels existants. Importez uniquement les contenus que vous avez le droit d’utiliser. Catalogue partagé : réservé aux administrateurs.", "Creates a new version without overwriting existing frameworks. Only import content you are entitled to use. Shared catalogue: administrators only.")}</Alert>
      <TextField label={t("Nom du référentiel", "Framework name")} value={name} disabled={busy || reading} inputProps={{ maxLength: 180 }} onChange={e => { setName(e.target.value); resetPreview(); }} />
      <TextField label="Version" value={version} disabled={busy || reading} inputProps={{ maxLength: 50 }} onChange={e => { setVersion(e.target.value); resetPreview(); }} />
      <Typography variant="body2">{t("CSV UTF-8, virgule ou point-virgule, 1 Mio et 500 exigences maximum. Colonne status optionnelle : ACTIVE, INACTIVE ou ARCHIVED ; ACTIVE par défaut. Colonnes :", "UTF-8 CSV, comma or semicolon, maximum 1 MiB and 500 requirements. Optional status column: ACTIVE, INACTIVE or ARCHIVED; defaults to ACTIVE. Columns:")}</Typography>
      <Typography component="code" sx={{ overflowWrap: "anywhere" }}>reference,title,category,description,parentReference</Typography>
      <Button component="a" download="riskpilot-framework-template.csv" href={`data:text/csv;charset=utf-8,${encodeURIComponent("reference,title,category,description,parentReference\nA,Access management,Security,,\nA.1,Review access rights,Security,,A\n")}`}>{t("Télécharger le modèle CSV", "Download CSV template")}</Button>
      <Button component="label" disabled={busy || reading} variant="outlined">{t("Choisir le fichier CSV", "Choose CSV file")}<input hidden type="file" accept=".csv,text/csv" onChange={async e => {
        const file = e.target.files?.[0]; e.target.value = ""; resetPreview(); setCsv("");
        if (!file) return;
        if (file.size > 1048576) { setError(t("Le fichier dépasse 1 Mio.", "File exceeds 1 MiB.")); return; }
        setReading(true);
        try { setCsv(new TextDecoder("utf-8", { fatal: true }).decode(await file.arrayBuffer())); }
        catch { setError(t("Fichier UTF-8 illisible.", "Cannot read UTF-8 file.")); }
        finally { setReading(false); }
      }} /></Button>
      {csv && <Typography variant="body2">{t("Fichier chargé : prévisualisez avant de confirmer.", "File loaded: preview before confirming.")}</Typography>}
      {error && <Alert severity="error">{error}</Alert>}
      {preview && <><Alert severity="success">{preview.count} {t("exigences validées. Aucune donnée enregistrée à ce stade.", "requirements validated. Nothing has been saved yet.")}</Alert><Typography variant="body2">{t("Aperçu des 20 premières exigences :", "Preview of the first 20 requirements:")}</Typography><Stack component="ul" sx={{ pl: 3, overflowWrap: "anywhere" }}>{preview.requirements.slice(0, 20).map(row => <Typography component="li" key={row.reference}>{row.reference} — {row.title}{row.parentReference ? ` (${t("parent", "parent")}: ${row.parentReference})` : ""} — {row.status === "ARCHIVED" ? t("Archivée", "Archived") : row.status === "INACTIVE" ? t("Inactive", "Inactive") : t("Active", "Active")}</Typography>)}</Stack></>}
    </Stack></DialogContent>
    <DialogActions sx={{ flexWrap: "wrap", gap: 1 }}><Button onClick={onClose} disabled={busy || reading}>{t("Fermer", "Close")}</Button><Button variant="contained" disabled={busy || reading || !name.trim() || !version.trim() || !csv} onClick={() => void submit()}>{busy ? t("Traitement…", "Processing…") : preview ? t("Confirmer la création", "Confirm creation") : t("Prévisualiser", "Preview")}</Button></DialogActions>
  </Dialog>;
}
