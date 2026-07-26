import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Checkbox,
  FormControlLabel,
  MenuItem,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import axios from "axios";
import { useState, type FormEvent } from "react";
import { api } from "../api/client";
import { useTranslation } from "../i18n/useTranslation";
import { actionFieldKey } from "./actionFieldKey";

type Field = {
  id: number;
  key: string;
  label: string;
  type: string;
  order: number;
  visible: boolean;
  required: boolean;
};
const initial = {
  key: "",
  label: "",
  type: "TEXT",
  order: 0,
  visible: true,
  required: false,
  options: [] as string[],
};

export function ActionFieldsPage() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const [form, setForm] = useState(initial);
  const [keyManuallyEdited, setKeyManuallyEdited] = useState(false);
  const fields = useQuery({
    queryKey: ["action-custom-fields"],
    queryFn: async () => (await api.get<Field[]>("/action-custom-fields")).data,
  });
  const create = useMutation({
    mutationFn: () => api.post("/action-custom-fields", form),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["action-custom-fields"] });
      setForm(initial);
      setKeyManuallyEdited(false);
    },
  });
  const keyIsValid = /^[a-z][a-z0-9_]{0,79}$/.test(form.key);
  const errorCode = axios.isAxiosError(create.error)
    ? create.error.response?.data?.code
    : undefined;
  const createError =
    errorCode === "FIELD_KEY_EXISTS"
      ? t("actionFields.duplicateError")
      : errorCode === "VALIDATION_ERROR"
        ? t("actionFields.validationError")
        : t("actionFields.createError");
  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!keyIsValid) return;
    create.mutate();
  };
  return (
    <Stack spacing={3}>
      <div>
        <Typography variant="h4" fontWeight={800}>
          {t("actionFields.title")}
        </Typography>
        <Typography color="text.secondary">
          {t("actionFields.description")}
        </Typography>
      </div>
      {create.isError && <Alert severity="error">{createError}</Alert>}
      <Card>
        <CardContent>
          <form onSubmit={submit}>
            <Stack spacing={2}>
              <TextField
                required
                label={t("actionFields.key")}
                helperText={
                  form.key && !keyIsValid
                    ? t("actionFields.keyInvalid")
                    : t("actionFields.keyHelp")
                }
                error={Boolean(form.key) && !keyIsValid}
                inputProps={{
                  minLength: 1,
                  maxLength: 80,
                  pattern: "[a-z][a-z0-9_]{0,79}",
                }}
                value={form.key}
                onChange={(e) => {
                  setKeyManuallyEdited(true);
                  setForm({ ...form, key: e.target.value });
                }}
              />
              <TextField
                required
                label={t("actionFields.label")}
                value={form.label}
                onChange={(e) => {
                  const label = e.target.value;
                  setForm({
                    ...form,
                    label,
                    key: keyManuallyEdited ? form.key : actionFieldKey(label),
                  });
                }}
              />
              <TextField
                select
                label={t("actionFields.type")}
                value={form.type}
                onChange={(e) => setForm({ ...form, type: e.target.value })}
              >
                {["TEXT", "NUMBER", "DATE", "BOOLEAN", "SELECT", "URL"].map(
                  (type) => (
                    <MenuItem key={type} value={type}>
                      {type}
                    </MenuItem>
                  ),
                )}
              </TextField>
              <TextField
                type="number"
                label={t("actionFields.order")}
                value={form.order}
                onChange={(e) =>
                  setForm({ ...form, order: Number(e.target.value) })
                }
              />
              <Stack direction="row">
                <FormControlLabel
                  control={
                    <Checkbox
                      checked={form.visible}
                      onChange={(e) =>
                        setForm({ ...form, visible: e.target.checked })
                      }
                    />
                  }
                  label={t("actionFields.visible")}
                />
                <FormControlLabel
                  control={
                    <Checkbox
                      checked={form.required}
                      onChange={(e) =>
                        setForm({ ...form, required: e.target.checked })
                      }
                    />
                  }
                  label={t("actionFields.required")}
                />
              </Stack>
              <Button
                type="submit"
                variant="contained"
                disabled={create.isPending || !keyIsValid || !form.label.trim()}
              >
                {create.isPending
                  ? t("actionFields.creating")
                  : t("actionFields.create")}
              </Button>
            </Stack>
          </form>
        </CardContent>
      </Card>
      <Stack spacing={1}>
        {fields.data?.map((field) => (
          <Card key={field.id}>
            <CardContent>
              <Typography fontWeight={700}>{field.label}</Typography>
              <Typography variant="body2" color="text.secondary">
                {field.key} · {field.type} ·{" "}
                {t("actionFields.order").toLowerCase()} {field.order} ·{" "}
                {field.required
                  ? t("actionFields.required").toLowerCase()
                  : t("actionFields.optional")}
              </Typography>
            </CardContent>
          </Card>
        ))}
      </Stack>
    </Stack>
  );
}
