import { Search, LayoutDashboard, BarChart3, ArrowLeftRight, CreditCard, History, Bell, Settings, HelpCircle, ChevronDown, ChevronUp, Zap } from "lucide-react";
import { useState } from "react";
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuItem,
  SidebarMenuButton,
  SidebarGroup,
  SidebarGroupLabel,
  SidebarGroupContent,
  useSidebar,
} from "@/components/ui/sidebar";

const AppSidebar = () => {
  const [transactionOpen, setTransactionOpen] = useState(false);
  const [cardOpen, setCardOpen] = useState(false);
  const { state } = useSidebar();
  const isCollapsed = state === "collapsed";

  return (
    <Sidebar collapsible="icon" className="border-r border-border/30 bg-card/80 backdrop-blur-2xl">
      <SidebarHeader className="px-4 py-4">
        <div className="flex items-center gap-2.5">
          <div className="w-9 h-9 bg-primary/90 backdrop-blur-sm rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-primary/20">
            <span className="text-primary-foreground font-bold text-sm">F</span>
          </div>
          {!isCollapsed && <span className="font-semibold text-lg text-foreground">Fundcy</span>}
        </div>
      </SidebarHeader>

      {/* Search */}
      {!isCollapsed && (
        <div className="px-4 mb-4">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
            <input
              type="text"
              placeholder="Cari..."
              className="w-full h-10 pl-9 pr-12 bg-muted/30 backdrop-blur-md border border-border/30 rounded-xl text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary/30 transition-all"
            />
            <span className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground font-medium bg-muted/50 px-1.5 py-0.5 rounded">⌘S</span>
          </div>
        </div>
      )}

      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupLabel className="text-muted-foreground/70">Menu</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              {/* Dashboard - Active */}
              <SidebarMenuItem>
                <SidebarMenuButton isActive tooltip="Dasbor" className="h-11 text-sm data-[active=true]:bg-primary/10 data-[active=true]:backdrop-blur-md data-[active=true]:border data-[active=true]:border-primary/20">
                  <LayoutDashboard className="w-5 h-5" />
                  <span>Dasbor</span>
                </SidebarMenuButton>
              </SidebarMenuItem>

              {/* Analytics */}
              <SidebarMenuItem>
                <SidebarMenuButton tooltip="Analitik" className="h-11 text-sm hover:bg-muted/30 hover:backdrop-blur-md">
                  <BarChart3 className="w-5 h-5" />
                  <span>Analitik</span>
                  {!isCollapsed && (
                    <span className="ml-auto w-5 h-5 rounded-lg bg-muted/50 backdrop-blur-sm flex items-center justify-center text-xs font-medium">2</span>
                  )}
                </SidebarMenuButton>
              </SidebarMenuItem>

              {/* Transaction */}
              <SidebarMenuItem>
                <SidebarMenuButton onClick={() => setTransactionOpen(!transactionOpen)} tooltip="Transaksi" className="h-11 text-sm hover:bg-muted/30 hover:backdrop-blur-md">
                  <ArrowLeftRight className="w-5 h-5" />
                  <span>Transaksi</span>
                  {!isCollapsed && (transactionOpen ? <ChevronUp className="w-4 h-4 ml-auto" /> : <ChevronDown className="w-4 h-4 ml-auto" />)}
                </SidebarMenuButton>
              </SidebarMenuItem>
              {transactionOpen && !isCollapsed && (
                <div className="ml-8 space-y-0.5 border-l border-border/30 pl-4">
                  <a href="#" className="block py-2 text-sm text-sidebar-foreground hover:text-foreground transition-colors">Debit</a>
                  <a href="#" className="block py-2 text-sm text-sidebar-foreground hover:text-foreground transition-colors">Kredit</a>
                  <a href="#" className="block py-2 text-sm text-sidebar-foreground hover:text-foreground transition-colors">Pinjaman</a>
                </div>
              )}

              {/* Card */}
              <SidebarMenuItem>
                <SidebarMenuButton onClick={() => setCardOpen(!cardOpen)} tooltip="Kartu" className="h-11 text-sm hover:bg-muted/30 hover:backdrop-blur-md">
                  <CreditCard className="w-5 h-5" />
                  <span>Kartu</span>
                  {!isCollapsed && <ChevronDown className="w-4 h-4 ml-auto" />}
                </SidebarMenuButton>
              </SidebarMenuItem>

              {/* History */}
              <SidebarMenuItem>
                <SidebarMenuButton tooltip="Riwayat" className="h-11 text-sm hover:bg-muted/30 hover:backdrop-blur-md">
                  <History className="w-5 h-5" />
                  <span>Riwayat</span>
                  {!isCollapsed && (
                    <span className="ml-auto w-5 h-5 rounded-lg bg-muted/50 backdrop-blur-sm flex items-center justify-center text-xs font-medium">8</span>
                  )}
                </SidebarMenuButton>
              </SidebarMenuItem>

              {/* Notifications */}
              <SidebarMenuItem>
                <SidebarMenuButton tooltip="Notifikasi" className="h-11 text-sm hover:bg-muted/30 hover:backdrop-blur-md">
                  <Bell className="w-5 h-5" />
                  <span>Notifikasi</span>
                  {!isCollapsed && (
                    <span className="ml-auto w-5 h-5 rounded-lg bg-primary/20 backdrop-blur-sm flex items-center justify-center text-xs font-medium text-primary">4</span>
                  )}
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>

        {/* Tools */}
        <SidebarGroup>
          <SidebarGroupLabel className="text-muted-foreground/70">Alat</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              <SidebarMenuItem>
                <SidebarMenuButton tooltip="Pengaturan" className="h-11 text-sm hover:bg-muted/30 hover:backdrop-blur-md">
                  <Settings className="w-5 h-5" />
                  <span>Pengaturan</span>
                </SidebarMenuButton>
              </SidebarMenuItem>
              <SidebarMenuItem>
                <SidebarMenuButton tooltip="Pusat Bantuan" className="h-11 text-sm hover:bg-muted/30 hover:backdrop-blur-md">
                  <HelpCircle className="w-5 h-5" />
                  <span>Pusat Bantuan</span>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>

      {/* Upgrade Card */}
      {!isCollapsed && (
        <SidebarFooter className="p-4">
          <div className="bg-gradient-to-br from-primary/10 to-accent/20 backdrop-blur-xl rounded-2xl p-4 border border-primary/10 shadow-lg shadow-primary/5">
            <div className="flex items-start justify-between mb-2">
              <h4 className="font-semibold text-sm text-foreground">Upgrade Pro! 👑</h4>
              <button className="text-muted-foreground hover:text-foreground transition-colors">
                <span className="text-lg leading-none">×</span>
              </button>
            </div>
            <p className="text-xs text-muted-foreground mb-4">Produktivitas lebih tinggi dengan fitur lengkap</p>
            <div className="flex items-center gap-3">
              <button className="flex items-center gap-1.5 px-4 py-2 bg-primary/90 backdrop-blur-sm text-primary-foreground rounded-xl text-xs font-medium hover:bg-primary transition-all shadow-lg shadow-primary/20">
                <Zap className="w-3.5 h-3.5" />
                Upgrade
              </button>
              <a href="#" className="text-xs text-muted-foreground hover:text-foreground transition-colors">Pelajari</a>
            </div>
          </div>
        </SidebarFooter>
      )}
    </Sidebar>
  );
};

export default AppSidebar;
