import { Download, ChevronDown, Menu } from "lucide-react";
import ThemeToggle from "@/components/ThemeToggle";
import { useSidebar } from "@/components/ui/sidebar";

const Header = () => {
  const { toggleSidebar, isMobile } = useSidebar();

  return (
    <header className="flex items-center justify-between py-4 px-4 md:py-5 md:px-6 animate-fade-in">
      <div className="flex items-center gap-3 md:gap-4 flex-1 min-w-0">
        {isMobile && (
          <button 
            onClick={toggleSidebar}
            className="w-9 h-9 md:w-10 md:h-10 rounded-xl bg-card/60 backdrop-blur-xl border border-border/30 flex items-center justify-center hover:bg-card/80 transition-all shadow-lg shadow-primary/5 flex-shrink-0"
          >
            <Menu className="w-5 h-5 text-muted-foreground" />
          </button>
        )}
        <div className="min-w-0">
          <h1 className="text-xl md:text-2xl font-semibold text-foreground truncate">Dasbor</h1>
          <p className="text-xs md:text-sm text-muted-foreground mt-0.5 hidden sm:block">Pantau, analisis, dan tingkatkan performa keuanganmu</p>
        </div>
      </div>
      
      <div className="flex items-center gap-2 md:gap-3 flex-shrink-0">
        <div className="bg-card/60 backdrop-blur-xl rounded-full border border-border/30 shadow-lg shadow-primary/5">
          <ThemeToggle />
        </div>
        <button className="w-9 h-9 md:w-10 md:h-10 rounded-full bg-card/60 backdrop-blur-xl border border-border/30 flex items-center justify-center hover:bg-card/80 transition-all shadow-lg shadow-primary/5 hidden sm:flex">
          <Download className="w-4 h-4 md:w-5 md:h-5 text-muted-foreground" />
        </button>
        
        <div className="flex items-center gap-2 md:gap-3 ml-1 md:ml-2 cursor-pointer bg-card/60 backdrop-blur-xl rounded-full px-2 py-1.5 md:px-3 md:py-2 border border-border/30 shadow-lg shadow-primary/5 hover:bg-card/80 transition-all">
          <img 
            src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=100&h=100&fit=crop&crop=face"
            alt="Arthur Elmani"
            className="w-7 h-7 md:w-8 md:h-8 rounded-full object-cover ring-2 ring-primary/20"
          />
          <div className="hidden lg:block">
            <p className="text-sm font-medium text-foreground leading-tight">Arthur Elmani</p>
            <p className="text-xs text-muted-foreground">arthur@fundcy.com</p>
          </div>
          <ChevronDown className="w-4 h-4 text-muted-foreground hidden lg:block" />
        </div>
      </div>
    </header>
  );
};

export default Header;
