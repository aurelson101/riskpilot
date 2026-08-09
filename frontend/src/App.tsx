import {
  AdminPanelSettingsOutlined,
  AccountTreeOutlined,
  Inventory2Outlined,
  BugReportOutlined,
  GppMaybeOutlined,
  DashboardOutlined,
  Logout,
  ShieldOutlined,
  AssessmentOutlined,
  GridViewOutlined,
  VerifiedUserOutlined,
  TaskAltOutlined,
  NotificationsOutlined,
  FactCheckOutlined,
  AccountCircleOutlined,
  BusinessOutlined,
  HistoryOutlined,
  DescriptionOutlined,
  SettingsOutlined,
  MenuOutlined,
  ChevronLeftOutlined,
  ChevronRightOutlined,
  ExpandLess,
  ExpandMore,
  FolderCopyOutlined,
} from "@mui/icons-material";
import {
  AppBar,
  Alert,
  Avatar,
  Box,
  Button,
  CircularProgress,
  Container,
  Drawer,
  List,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  Stack,
  Toolbar,
  Typography,
  Collapse,
  Divider,
  IconButton,
  Tooltip,
  useMediaQuery,
} from "@mui/material";
import { useTheme } from "@mui/material/styles";
import {
  Navigate,
  Outlet,
  Route,
  Routes,
  useLocation,
  useNavigate,
} from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { useAuth } from "./auth/useAuth";
import { hasAnyRole } from "./auth/roles";
import { api } from "./api/client";
import type { IsmsDocument } from "./api/types";
import { LanguageBoundary } from "./i18n/LanguageBoundary";
import { ConfirmationProvider } from "./components/ConfirmationProvider";
import {
  lazy,
  Suspense,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";

const LoginPage = lazy(() =>
  import("./pages/LoginPage").then((module) => ({ default: module.LoginPage })),
);
const LOCALE_STORAGE_KEY = "riskpilot.interfaceLocale";

function initialInterfaceLocale(): "fr" | "en" {
  const stored = localStorage.getItem(LOCALE_STORAGE_KEY);
  if (stored === "fr" || stored === "en") return stored;
  return navigator.language.toLowerCase().startsWith("fr") ? "fr" : "en";
}
const ResetPasswordPage = lazy(() =>
  import("./pages/ResetPasswordPage").then((module) => ({
    default: module.ResetPasswordPage,
  })),
);
const InventoryPage = lazy(() =>
  import("./pages/InventoryPage").then((module) => ({
    default: module.InventoryPage,
  })),
);
const UsersPage = lazy(() =>
  import("./pages/UsersPage").then((module) => ({ default: module.UsersPage })),
);
const RisksPage = lazy(() =>
  import("./pages/RisksPage").then((module) => ({ default: module.RisksPage })),
);
const RiskMatrixPage = lazy(() =>
  import("./pages/RiskMatrixPage").then((module) => ({
    default: module.RiskMatrixPage,
  })),
);
const ActionsPage = lazy(() =>
  import("./pages/ActionsPage").then((module) => ({
    default: module.ActionsPage,
  })),
);
const IndicatorsPage = lazy(() =>
  import("./pages/IndicatorsPage").then((module) => ({
    default: module.IndicatorsPage,
  })),
);
const NotificationsPage = lazy(() =>
  import("./pages/NotificationsPage").then((module) => ({
    default: module.NotificationsPage,
  })),
);
const CompliancePage = lazy(() =>
  import("./pages/CompliancePage").then((module) => ({
    default: module.CompliancePage,
  })),
);
const DashboardPage = lazy(() =>
  import("./pages/DashboardPage").then((module) => ({
    default: module.DashboardPage,
  })),
);
const ProfilePage = lazy(() =>
  import("./pages/ProfilePage").then((module) => ({
    default: module.ProfilePage,
  })),
);
const OrganizationsPage = lazy(() =>
  import("./pages/OrganizationsPage").then((module) => ({
    default: module.OrganizationsPage,
  })),
);
const AuditLogsPage = lazy(() =>
  import("./pages/AuditLogsPage").then((module) => ({
    default: module.AuditLogsPage,
  })),
);
const ExecutiveReportPage = lazy(() =>
  import("./pages/ExecutiveReportPage").then((module) => ({
    default: module.ExecutiveReportPage,
  })),
);
const EmailSettingsPage = lazy(() =>
  import("./pages/EmailSettingsPage").then((module) => ({
    default: module.EmailSettingsPage,
  })),
);
const IsmsDocumentsPage = lazy(() =>
  import("./pages/IsmsDocumentsPage").then((module) => ({
    default: module.IsmsDocumentsPage,
  })),
);
const PublicDocumentPage = lazy(() =>
  import("./pages/PublicDocumentPage").then((module) => ({
    default: module.PublicDocumentPage,
  })),
);
const ThirdPartiesPage = lazy(() =>
  import("./pages/ThirdPartiesPage").then((module) => ({
    default: module.ThirdPartiesPage,
  })),
);
const ResiliencePage = lazy(() =>
  import("./pages/ResiliencePage").then((module) => ({
    default: module.ResiliencePage,
  })),
);
const RegulatoryPage = lazy(() =>
  import("./pages/RegulatoryPage").then((module) => ({
    default: module.RegulatoryPage,
  })),
);
const IntegrationSettingsPage = lazy(() =>
  import("./pages/IntegrationSettingsPage").then((module) => ({
    default: module.IntegrationSettingsPage,
  })),
);
const ActionFieldsPage = lazy(() =>
  import("./pages/ActionFieldsPage").then((module) => ({
    default: module.ActionFieldsPage,
  })),
);
const OperationsPage = lazy(() =>
  import("./pages/OperationsPage").then((module) => ({
    default: module.OperationsPage,
  })),
);
const DecisionWorkspacePage = lazy(() =>
  import("./pages/DecisionWorkspacePage").then((module) => ({
    default: module.DecisionWorkspacePage,
  })),
);
const ExperimentsPage = lazy(() =>
  import("./pages/ExperimentsPage").then((module) => ({
    default: module.ExperimentsPage,
  })),
);
const AnalysisWorkspacePage = lazy(() =>
  import("./pages/AnalysisWorkspacePage").then((module) => ({
    default: module.AnalysisWorkspacePage,
  })),
);
const AnnualReportsPage = lazy(() =>
  import("./pages/AnnualReportsPage").then((module) => ({
    default: module.AnnualReportsPage,
  })),
);

const drawerWidth = 264;
const collapsedDrawerWidth = 76;

function ProtectedRoute() {
  return useAuth().token ? <Outlet /> : <Navigate to="/login" replace />;
}

function RoleRoute({
  allowedRoles,
  children,
}: {
  allowedRoles: readonly string[];
  children: ReactNode;
}) {
  const { user } = useAuth();
  if (!hasAnyRole(user?.roles, allowedRoles)) {
    return (
      <Alert severity="warning">
        Accès refusé. Votre rôle ne permet pas d’ouvrir cette page.
      </Alert>
    );
  }
  return children;
}

const adminRoles = ["ROLE_ADMIN", "ROLE_SUPER_ADMIN"] as const;

function Layout() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const theme = useTheme();
  const mobile = useMediaQuery(theme.breakpoints.down("md"));
  const [mobileOpen, setMobileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const riskActive = [
    "/risks",
    "/analysis-workspace",
    "/risk-matrix",
    "/threats",
    "/vulnerabilities",
  ].some((path) => location.pathname === path);
  const [riskOpen, setRiskOpen] = useState(riskActive);
  const steeringActive = [
    "/actions",
    "/operations",
    "/decision",
    "/experiments",
    "/indicators",
    "/annual-reports",
  ].some((path) => location.pathname === path);
  const [steeringOpen, setSteeringOpen] = useState(steeringActive);
  const complianceActive = [
    "/security-controls",
    "/compliance",
    "/regulatory",
  ].some((path) => location.pathname === path);
  const [complianceOpen, setComplianceOpen] = useState(complianceActive);
  const settingsActive =
    location.pathname === "/profile" ||
    location.pathname.startsWith("/administration");
  const [settingsOpen, setSettingsOpen] = useState(settingsActive);
  const assetsActive =
    location.pathname === "/assets" || location.pathname.startsWith("/assets/");
  const [assetsOpen, setAssetsOpen] = useState(assetsActive);
  const ismsActive = location.pathname === "/isms-documents";
  const [ismsOpen, setIsmsOpen] = useState(ismsActive);
  const ismsDocuments = useQuery({
    queryKey: ["isms-documents"],
    queryFn: async () =>
      (await api.get<IsmsDocument[]>("/isms-documents")).data,
    enabled: Boolean(user) && (ismsOpen || ismsActive),
    staleTime: 5 * 60 * 1000,
  });
  const ismsCategories = useMemo(
    () =>
      [...new Set((ismsDocuments.data ?? []).map((item) => item.category))]
        .filter(
          (category) =>
            Boolean(category) &&
            category.trim().toLocaleLowerCase(user?.locale ?? "fr") !==
              "publications récentes".toLocaleLowerCase(user?.locale ?? "fr"),
        )
        .sort((left, right) => left.localeCompare(right, "fr")),
    [ismsDocuments.data, user?.locale],
  );
  const isAdmin = user?.roles.some((role) =>
    ["ROLE_ADMIN", "ROLE_SUPER_ADMIN"].includes(role),
  );
  const currentDrawerWidth = collapsed ? collapsedDrawerWidth : drawerWidth;

  useEffect(() => {
    if (settingsActive) setSettingsOpen(true);
  }, [settingsActive]);

  useEffect(() => {
    if (riskActive) setRiskOpen(true);
  }, [riskActive]);

  useEffect(() => {
    if (steeringActive) setSteeringOpen(true);
  }, [steeringActive]);

  useEffect(() => {
    if (complianceActive) setComplianceOpen(true);
  }, [complianceActive]);

  useEffect(() => {
    if (assetsActive) setAssetsOpen(true);
  }, [assetsActive]);

  useEffect(() => {
    if (ismsActive) setIsmsOpen(true);
  }, [ismsActive]);

  if (!user) {
    return (
      <Stack minHeight="100vh" alignItems="center" justifyContent="center">
        <CircularProgress aria-label="Chargement du profil" />
      </Stack>
    );
  }

  const go = (path: string) => {
    navigate(path);
    if (mobile) setMobileOpen(false);
  };
  const titleByPath: Record<string, string> = {
    "/": "Tableau de bord",
    "/risks": "Risques",
    "/actions": "Plans d’action",
    "/operations": "Pilotage opérationnel",
    "/decision": "Décision et différenciation",
    "/experiments": "Expérimentations sous contrôle",
    "/analysis-workspace": "Analyses et capitalisation",
    "/indicators": "Indicateurs",
    "/risk-matrix": "Matrice des risques",
    "/scopes": "Périmètres",
    "/assets": "Actifs",
    "/assets/hardware": "Actifs matériels",
    "/assets/software": "Actifs logiciels",
    "/assets/information": "Actifs informationnels",
    "/threats": "Menaces",
    "/compliance": "Conformité",
    "/security-controls": "Mesures de sécurité",
    "/third-parties": "Tiers et fournisseurs",
    "/resilience": "Incidents et continuité",
    "/regulatory": "Vie privée et obligations",
    "/vulnerabilities": "Vulnérabilités",
    "/notifications": "Notifications",
    "/reports/executive": "Rapport exécutif",
    "/annual-reports": "Rapports annuels",
    "/isms-documents": "Documents ISMS",
    "/profile": "Mon profil",
    "/administration/users": "Utilisateurs",
    "/administration/organizations": "Organisations",
    "/administration/audit-logs": "Journal d’audit",
    "/administration/email-settings": "Paramètres email",
    "/administration/integrations": "Identité et intégrations",
    "/administration/action-fields": "Colonnes des actions",
  };

  function NavItem({
    path,
    label,
    icon,
    nested = false,
  }: {
    path: string;
    label: string;
    icon: ReactNode;
    nested?: boolean;
  }) {
    const [targetPath, targetSearch = ""] = path.split("?");
    const selected =
      location.pathname === targetPath &&
      (targetSearch
        ? location.search === `?${targetSearch}`
        : location.pathname !== "/isms-documents" || !location.search);
    const button = (
      <ListItemButton
        selected={selected}
        onClick={() => go(path)}
        sx={{
          minHeight: 44,
          borderRadius: 1.5,
          mb: 0.25,
          pl: nested && !collapsed ? 3 : 2,
          justifyContent: collapsed ? "center" : "initial",
        }}
      >
        <ListItemIcon
          sx={{
            minWidth: collapsed ? 0 : 42,
            color: "inherit",
            justifyContent: "center",
          }}
        >
          {icon}
        </ListItemIcon>
        {!collapsed && (
          <ListItemText
            primary={label}
            primaryTypographyProps={{ fontSize: nested ? 13.5 : 14 }}
          />
        )}
      </ListItemButton>
    );
    return collapsed && !mobile ? (
      <Tooltip key={path} title={label} placement="right">
        {button}
      </Tooltip>
    ) : (
      button
    );
  }

  function NavGroup({
    id,
    label,
    icon,
    active,
    open,
    onToggle,
    children,
  }: {
    id: string;
    label: string;
    icon: ReactNode;
    active: boolean;
    open: boolean;
    onToggle: () => void;
    children: ReactNode;
  }) {
    return (
      <>
        <Tooltip title={collapsed && !mobile ? label : ""} placement="right">
          <ListItemButton
            aria-label={label}
            aria-expanded={open}
            aria-controls={`${id}-navigation`}
            selected={active}
            onClick={() => {
              if (collapsed) {
                setCollapsed(false);
                if (!open) onToggle();
                return;
              }
              onToggle();
            }}
            sx={{
              minHeight: 44,
              borderRadius: 1.5,
              mb: 0.25,
              justifyContent: collapsed ? "center" : "initial",
            }}
          >
            <ListItemIcon
              sx={{
                minWidth: collapsed ? 0 : 42,
                color: "inherit",
                justifyContent: "center",
              }}
            >
              {icon}
            </ListItemIcon>
            {!collapsed && (
              <>
                <ListItemText
                  primary={label}
                  primaryTypographyProps={{ fontSize: 14 }}
                />
                {open ? <ExpandLess /> : <ExpandMore />}
              </>
            )}
          </ListItemButton>
        </Tooltip>
        {!collapsed && (
          <Collapse in={open} timeout="auto" unmountOnExit>
            <List id={`${id}-navigation`} component="div" disablePadding>
              {children}
            </List>
          </Collapse>
        )}
      </>
    );
  }

  const drawerContent = (
    <Box
      sx={{
        height: "100%",
        display: "flex",
        flexDirection: "column",
        overflow: "hidden",
      }}
    >
      <Toolbar
        sx={{
          px: collapsed ? 2 : 2.5,
          justifyContent: collapsed ? "center" : "flex-start",
          flexShrink: 0,
        }}
      >
        <ShieldOutlined
          sx={{ color: "#54a3ff", mr: collapsed ? 0 : 1.5, fontSize: 32 }}
        />
        {!collapsed && (
          <Typography variant="h5" fontWeight={750}>
            RiskPilot
          </Typography>
        )}
      </Toolbar>
      <List
        component="nav"
        aria-label="Navigation principale"
        sx={{ px: 1, py: 1, overflowY: "auto", overflowX: "hidden" }}
      >
        <NavItem
          path="/"
          label="Tableau de bord"
          icon={<DashboardOutlined />}
        />
        <NavGroup
          id="risk"
          label="Gestion des risques"
          icon={<AssessmentOutlined />}
          active={riskActive}
          open={riskOpen}
          onToggle={() => setRiskOpen((value) => !value)}
        >
          <NavItem
            nested
            path="/risks"
            label="Registre des risques"
            icon={<AssessmentOutlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/analysis-workspace"
            label="Analyses de risques"
            icon={<GridViewOutlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/risk-matrix"
            label="Matrice des risques"
            icon={<GridViewOutlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/threats"
            label="Menaces"
            icon={<GppMaybeOutlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/vulnerabilities"
            label="Vulnérabilités"
            icon={<BugReportOutlined fontSize="small" />}
          />
        </NavGroup>
        <NavGroup
          id="steering"
          label="Pilotage"
          icon={<TaskAltOutlined />}
          active={steeringActive}
          open={steeringOpen}
          onToggle={() => setSteeringOpen((value) => !value)}
        >
          <NavItem
            nested
            path="/actions"
            label="Plans d’action"
            icon={<TaskAltOutlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/operations"
            label="Mes tâches et programmes"
            icon={<FactCheckOutlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/indicators"
            label="Indicateurs"
            icon={<AssessmentOutlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/annual-reports"
            label="Rapports annuels"
            icon={<HistoryOutlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/decision"
            label="Décision et simulations"
            icon={<GridViewOutlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/experiments"
            label="Assistant et bibliothèque"
            icon={<BugReportOutlined fontSize="small" />}
          />
        </NavGroup>
        <Divider sx={{ my: 1, borderColor: "rgba(255,255,255,.12)" }} />
        <NavItem
          path="/scopes"
          label="Périmètres"
          icon={<AccountTreeOutlined />}
        />
        <NavGroup
          id="assets"
          label="Actifs"
          icon={<Inventory2Outlined />}
          active={assetsActive}
          open={assetsOpen}
          onToggle={() => setAssetsOpen((value) => !value)}
        >
          <NavItem
            nested
            path="/assets"
            label="Tous les actifs"
            icon={<Inventory2Outlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/assets/hardware"
            label="Actifs matériels"
            icon={<Inventory2Outlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/assets/software"
            label="Actifs logiciels"
            icon={<Inventory2Outlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/assets/information"
            label="Actifs informationnels"
            icon={<Inventory2Outlined fontSize="small" />}
          />
        </NavGroup>
        <NavGroup
          id="compliance"
          label="Conformité et contrôles"
          icon={<VerifiedUserOutlined />}
          active={complianceActive}
          open={complianceOpen}
          onToggle={() => setComplianceOpen((value) => !value)}
        >
          <NavItem
            nested
            path="/compliance"
            label="Conformité"
            icon={<FactCheckOutlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/security-controls"
            label="Mesures de sécurité"
            icon={<VerifiedUserOutlined fontSize="small" />}
          />
          <NavItem
            nested
            path="/regulatory"
            label="Vie privée et obligations"
            icon={<VerifiedUserOutlined fontSize="small" />}
          />
        </NavGroup>
        <NavItem
          path="/third-parties"
          label="Tiers"
          icon={<BusinessOutlined />}
        />
        <NavItem
          path="/resilience"
          label="Résilience"
          icon={<ShieldOutlined />}
        />
        <NavGroup
          id="isms"
          label="Documents ISMS"
          icon={<FolderCopyOutlined />}
          active={ismsActive}
          open={ismsOpen}
          onToggle={() => setIsmsOpen((value) => !value)}
        >
          <NavItem
            nested
            path="/isms-documents"
            label="Publications récentes"
            icon={<DescriptionOutlined fontSize="small" />}
          />
          {ismsCategories.map((category) => (
            <NavItem
              key={category}
              nested
              path={`/isms-documents?category=${encodeURIComponent(category)}`}
              label={category}
              icon={<FolderCopyOutlined fontSize="small" />}
            />
          ))}
        </NavGroup>
        <Divider sx={{ my: 1, borderColor: "rgba(255,255,255,.12)" }} />
        <NavItem
          path="/notifications"
          label="Notifications"
          icon={<NotificationsOutlined />}
        />
        <NavItem
          path="/reports/executive"
          label="Rapport exécutif"
          icon={<DescriptionOutlined />}
        />
        <NavGroup
          id="settings"
          label="Paramètres"
          icon={<SettingsOutlined />}
          active={settingsActive}
          open={settingsOpen}
          onToggle={() => setSettingsOpen((value) => !value)}
        >
          <NavItem
            nested
            path="/profile"
            label="Mon profil et MFA"
            icon={<AccountCircleOutlined fontSize="small" />}
          />
          {isAdmin && (
            <NavItem
              nested
              path="/administration/action-fields"
              label="Colonnes des actions"
              icon={<GridViewOutlined fontSize="small" />}
            />
          )}
          {isAdmin && (
            <NavItem
              nested
              path="/administration/integrations"
              label="Identité et intégrations"
              icon={<AccountTreeOutlined fontSize="small" />}
            />
          )}
          {isAdmin && (
            <NavItem
              nested
              path="/administration/email-settings"
              label="Messagerie"
              icon={<SettingsOutlined fontSize="small" />}
            />
          )}
          {isAdmin && (
            <NavItem
              nested
              path="/administration/users"
              label="Utilisateurs"
              icon={<AdminPanelSettingsOutlined fontSize="small" />}
            />
          )}
          {isAdmin && (
            <NavItem
              nested
              path="/administration/organizations"
              label="Organisations"
              icon={<BusinessOutlined fontSize="small" />}
            />
          )}
          {isAdmin && (
            <NavItem
              nested
              path="/administration/audit-logs"
              label="Journal d’audit"
              icon={<HistoryOutlined fontSize="small" />}
            />
          )}
        </NavGroup>
      </List>
      <Box
        sx={{
          mt: "auto",
          p: 1,
          borderTop: "1px solid rgba(255,255,255,.12)",
          flexShrink: 0,
        }}
      >
        <Tooltip title={collapsed ? "Déconnexion" : ""} placement="right">
          <Button
            color="inherit"
            fullWidth
            startIcon={<Logout />}
            onClick={logout}
            sx={{
              justifyContent: collapsed ? "center" : "flex-start",
              minWidth: 0,
              "& .MuiButton-startIcon": { mr: collapsed ? 0 : 1 },
            }}
          >
            {!collapsed && "Déconnexion"}
          </Button>
        </Tooltip>
        {!mobile && (
          <Tooltip
            title={collapsed ? "Déployer le menu" : "Réduire le menu"}
            placement="right"
          >
            <IconButton
              aria-label={collapsed ? "Déployer le menu" : "Réduire le menu"}
              color="inherit"
              onClick={() => setCollapsed((value) => !value)}
              sx={{ width: "100%", borderRadius: 1.5, mt: 0.5 }}
            >
              {collapsed ? (
                <ChevronRightOutlined />
              ) : (
                <>
                  <ChevronLeftOutlined />
                  <Typography variant="caption" sx={{ ml: 1 }}>
                    Réduire
                  </Typography>
                </>
              )}
            </IconButton>
          </Tooltip>
        )}
      </Box>
    </Box>
  );

  return (
    <Box
      sx={{
        display: "flex",
        minHeight: "100vh",
        bgcolor: "#f4f7fb",
        width: "100%",
      }}
    >
      <AppBar
        position="fixed"
        color="inherit"
        elevation={0}
        sx={{
          ml: { md: `${currentDrawerWidth}px` },
          width: { xs: "100%", md: `calc(100% - ${currentDrawerWidth}px)` },
          transition: theme.transitions.create(["margin", "width"]),
        }}
      >
        <Toolbar
          sx={{ borderBottom: "1px solid #e5eaf1", px: { xs: 1.5, sm: 3 } }}
        >
          {mobile && (
            <IconButton
              edge="start"
              onClick={() => setMobileOpen(true)}
              aria-label="Ouvrir le menu"
              sx={{ mr: 1 }}
            >
              <MenuOutlined />
            </IconButton>
          )}
          <Typography
            variant="h6"
            noWrap
            sx={{ flexGrow: 1, fontSize: { xs: "1rem", sm: "1.25rem" } }}
          >
            {location.pathname === "/isms-documents" && location.search
              ? `Documents ISMS — ${new URLSearchParams(location.search).get("category") ?? "Vue d’ensemble"}`
              : (titleByPath[location.pathname] ?? "RiskPilot")}
          </Typography>
          <Stack direction="row" spacing={1.5} alignItems="center">
            <Avatar sx={{ bgcolor: "#1769e0", width: 34, height: 34 }}>
              {user.firstName[0]}
              {user.lastName[0]}
            </Avatar>
            <Box sx={{ display: { xs: "none", sm: "block" } }}>
              <Typography variant="body2" fontWeight={700}>
                {user.firstName} {user.lastName}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {user.organization.name}
              </Typography>
            </Box>
          </Stack>
        </Toolbar>
      </AppBar>
      <Drawer
        variant={mobile ? "temporary" : "permanent"}
        open={mobile ? mobileOpen : true}
        onClose={() => setMobileOpen(false)}
        ModalProps={{ keepMounted: true }}
        sx={{
          width: mobile ? drawerWidth : currentDrawerWidth,
          flexShrink: 0,
          "& .MuiDrawer-paper": {
            width: mobile ? drawerWidth : currentDrawerWidth,
            bgcolor: "#062b4b",
            color: "white",
            border: 0,
            transition: theme.transitions.create("width"),
          },
        }}
      >
        {drawerContent}
      </Drawer>
      <Box
        component="main"
        sx={{
          flexGrow: 1,
          minWidth: 0,
          pt: { xs: 9, sm: 10 },
          pb: { xs: 3, sm: 5 },
          width: { xs: "100%", md: `calc(100% - ${currentDrawerWidth}px)` },
          transition: theme.transitions.create("width"),
        }}
      >
        <Container maxWidth="xl" sx={{ px: { xs: 1.5, sm: 3 } }}>
          <Outlet />
        </Container>
      </Box>
    </Box>
  );
}

export default function App() {
  const { user } = useAuth();
  const [storedLocale, setStoredLocale] = useState(initialInterfaceLocale);
  const locale = user?.locale ?? storedLocale;

  useEffect(() => {
    if (!user?.locale) return;
    localStorage.setItem(LOCALE_STORAGE_KEY, user.locale);
    setStoredLocale(user.locale);
  }, [user?.locale]);

  return (
    <LanguageBoundary locale={locale}>
      <ConfirmationProvider>
        <Suspense
          fallback={
            <Stack minHeight="50vh" alignItems="center" justifyContent="center">
              <CircularProgress aria-label="Chargement de la page" />
            </Stack>
          }
        >
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/reset-password" element={<ResetPasswordPage />} />
            <Route
              path="/shared/documents/:token"
              element={<PublicDocumentPage />}
            />
            <Route element={<ProtectedRoute />}>
              <Route element={<Layout />}>
                <Route index element={<DashboardPage />} />
                <Route path="risks" element={<RisksPage />} />
                <Route path="actions" element={<ActionsPage />} />
                <Route path="operations" element={<OperationsPage />} />
                <Route path="decision" element={<DecisionWorkspacePage />} />
                <Route path="experiments" element={<ExperimentsPage />} />
                <Route
                  path="analysis-workspace"
                  element={<AnalysisWorkspacePage />}
                />
                <Route path="indicators" element={<IndicatorsPage />} />
                <Route path="annual-reports" element={<AnnualReportsPage />} />
                <Route path="notifications" element={<NotificationsPage />} />
                <Route path="compliance" element={<CompliancePage />} />
                <Route path="third-parties" element={<ThirdPartiesPage />} />
                <Route path="resilience" element={<ResiliencePage />} />
                <Route path="regulatory" element={<RegulatoryPage />} />
                <Route path="risk-matrix" element={<RiskMatrixPage />} />
                <Route
                  path="scopes"
                  element={<InventoryPage kind="scopes" />}
                />
                <Route
                  path="assets"
                  element={<InventoryPage kind="assets" />}
                />
                <Route
                  path="assets/hardware"
                  element={
                    <InventoryPage kind="assets" assetFamily="HARDWARE" />
                  }
                />
                <Route
                  path="assets/software"
                  element={
                    <InventoryPage kind="assets" assetFamily="SOFTWARE" />
                  }
                />
                <Route
                  path="assets/information"
                  element={
                    <InventoryPage kind="assets" assetFamily="INFORMATION" />
                  }
                />
                <Route
                  path="threats"
                  element={<InventoryPage kind="threats" />}
                />
                <Route
                  path="vulnerabilities"
                  element={<InventoryPage kind="vulnerabilities" />}
                />
                <Route
                  path="security-controls"
                  element={<InventoryPage kind="security-controls" />}
                />
                <Route
                  path="administration/users"
                  element={
                    <RoleRoute allowedRoles={adminRoles}>
                      <UsersPage />
                    </RoleRoute>
                  }
                />
                <Route
                  path="administration/organizations"
                  element={
                    <RoleRoute allowedRoles={adminRoles}>
                      <OrganizationsPage />
                    </RoleRoute>
                  }
                />
                <Route
                  path="administration/audit-logs"
                  element={
                    <RoleRoute allowedRoles={adminRoles}>
                      <AuditLogsPage />
                    </RoleRoute>
                  }
                />
                <Route path="profile" element={<ProfilePage />} />
                <Route
                  path="administration/email-settings"
                  element={
                    <RoleRoute allowedRoles={adminRoles}>
                      <EmailSettingsPage />
                    </RoleRoute>
                  }
                />
                <Route
                  path="administration/integrations"
                  element={
                    <RoleRoute allowedRoles={adminRoles}>
                      <IntegrationSettingsPage />
                    </RoleRoute>
                  }
                />
                <Route
                  path="administration/action-fields"
                  element={
                    <RoleRoute allowedRoles={adminRoles}>
                      <ActionFieldsPage />
                    </RoleRoute>
                  }
                />
                <Route
                  path="reports/executive"
                  element={<ExecutiveReportPage />}
                />
                <Route path="isms-documents" element={<IsmsDocumentsPage />} />
              </Route>
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Suspense>
      </ConfirmationProvider>
    </LanguageBoundary>
  );
}
