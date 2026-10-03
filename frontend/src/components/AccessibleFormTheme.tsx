import { createTheme, ThemeProvider } from "@mui/material/styles";
import type { ReactNode } from "react";
import type { TextFieldProps } from "@mui/material/TextField";

const theme = createTheme({
  components: {
    MuiTextField: {
      defaultProps: {
        slotProps: {
          inputLabel: (state) => {
            const select = (state as TextFieldProps).slotProps?.select;
            const native = state.SelectProps?.native ||
              (typeof select === "object" && select?.native);
            // Custom MUI selects are labelled by aria-labelledby, not HTML for.
            return state.select && !native ? { htmlFor: undefined } : {};
          },
        },
      },
    },
  },
});

export function AccessibleFormTheme({ children }: { children: ReactNode }) {
  return <ThemeProvider theme={theme}>{children}</ThemeProvider>;
}
