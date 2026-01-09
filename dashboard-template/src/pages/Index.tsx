import { useState, useCallback } from "react";
import { SidebarProvider, SidebarInset } from "@/components/ui/sidebar";
import { ThemeProvider } from "@/components/ThemeProvider";
import PullToRefresh from "@/components/PullToRefresh";
import AppSidebar from "@/components/dashboard/AppSidebar";
import Header from "@/components/dashboard/Header";
import BalanceCard from "@/components/dashboard/BalanceCard";
import IncomeCard from "@/components/dashboard/IncomeCard";
import ExpenseCard from "@/components/dashboard/ExpenseCard";
import MoneyFlowChart from "@/components/dashboard/MoneyFlowChart";
import RemainingMonthly from "@/components/dashboard/RemainingMonthly";
import TransactionHistory from "@/components/dashboard/TransactionHistory";
import { toast } from "@/hooks/use-toast";

const Index = () => {
  const [refreshKey, setRefreshKey] = useState(0);

  const handleRefresh = useCallback(async () => {
    // Simulate data refresh
    await new Promise(resolve => setTimeout(resolve, 1000));
    setRefreshKey(prev => prev + 1);
    toast({
      title: "Data diperbarui",
      description: "Semua data sudah yang terbaru",
    });
  }, []);

  return (
    <ThemeProvider defaultTheme="light" storageKey="fundcy-theme">
      <SidebarProvider>
        <div className="flex min-h-screen w-full bg-background gradient-bg">
          {/* Floating orbs for extra visual effect */}
          <div className="floating-orb floating-orb-1" />
          <div className="floating-orb floating-orb-2" />
          <div className="floating-orb floating-orb-3" />
          
          <AppSidebar />
          
          <SidebarInset className="flex flex-col">
            <Header />
            
            <PullToRefresh onRefresh={handleRefresh}>
              <div className="px-4 pb-4 md:px-6 md:pb-6" key={refreshKey}>
              {/* Top row cards */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-5 mb-4 md:mb-5">
                  <BalanceCard />
                  <IncomeCard />
                  <ExpenseCard />
                </div>
                
                {/* Chart row */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 md:gap-5 mb-4 md:mb-5">
                  <div className="lg:col-span-2">
                    <MoneyFlowChart />
                  </div>
                  <RemainingMonthly />
                </div>
                
                {/* Transaction table */}
                <TransactionHistory />
              </div>
            </PullToRefresh>
          </SidebarInset>
        </div>
      </SidebarProvider>
    </ThemeProvider>
  );
};

export default Index;
