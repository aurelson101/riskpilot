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
import { useState, type FormEvent } from "react";
import { api } from "../api/client";

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
  const client = useQueryClient();
  const [form, setForm] = useState(initial);
  const fields = useQuery({
    queryKey: ["action-custom-fields"],
    queryFn: async () => (await api.get<Field[]>("/action-custom-fields")).data,
  });
  const create = useMutation({
    mutationFn: () => api.post("/action-custom-fields", form),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["action-custom-fields"] });
      setForm(initial);
    },
  });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate();
  };
  return (
    <Stack spacing={3}>
      <div>
        <Typography variant="h4" fontWeight={800}>
          Action columns
        </Typography>
        <Typography color="text.secondary">
          Define organization-specific fields shown in action plans.
        </Typography>
      </div>
      {create.isError && (
        <Alert severity="error">The field could not be created.</Alert>
      )}
      <Card>
        <CardContent>
          <form onSubmit={submit}>
            <Stack spacing={2}>
              <TextField
                required
                label="Key"
                helperText="Lowercase letters, numbers and underscores"
                value={form.key}
                onChange={(e) => setForm({ ...form, key: e.target.value })}
              />
              <TextField
                required
                label="Label"
                value={form.label}
                onChange={(e) => setForm({ ...form, label: e.target.value })}
              />
              <TextField
                select
                label="Type"
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
                label="Display order"
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
                  label="Visible"
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
                  label="Required"
                />
              </Stack>
              <Button type="submit" variant="contained">
                Create column
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
                {field.key} · {field.type} · order {field.order} ·{" "}
                {field.required ? "required" : "optional"}
              </Typography>
            </CardContent>
          </Card>
        ))}
      </Stack>
    </Stack>
  );
}
