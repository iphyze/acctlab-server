import React, { useEffect, useState } from 'react';
import {
  BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer,
  PieChart, Pie, Cell,
  LineChart, Line
} from 'recharts';

interface PaymentReport {
  status_summary: {
    [key: string]: {
      count: number;
      total_amount: number;
    };
  };
  time_series: {
    [key: string]: {
      [status: string]: {
        count: number;
        amount: number;
      };
    };
  };
  trends: {
    total_requests: number;
    total_amount: number;
    pending_percentage: number;
    paid_percentage: number;
  };
}

const COLORS = ['#0088FE', '#00C49F', '#FFBB28'];

const PaymentDashboard: React.FC = () => {
  const [reportData, setReportData] = useState<PaymentReport | null>(null);
  const [timeFrame, setTimeFrame] = useState<string>('monthly');

  useEffect(() => {
    fetchReportData();
  }, [timeFrame]);

  const fetchReportData = async () => {
    try {
      const response = await fetch(`/api/request/advance/getReports.php?timeFrame=${timeFrame}`);
      const data = await response.json();
      if (data.status === 'Success') {
        setReportData(data.data);
      }
    } catch (error) {
      console.error('Error fetching report data:', error);
    }
  };

  if (!reportData) return <div>Loading...</div>;

  // Prepare data for status distribution pie chart
  const statusData = Object.entries(reportData.status_summary).map(([status, data]) => ({
    name: status,
    value: data.count
  }));

  // Prepare data for time series chart
  const timeSeriesData = Object.entries(reportData.time_series).map(([period, data]) => ({
    period,
    Pending: data.Pending.count,
    Paid: data.Paid.count,
    Unconfirmed: data.Unconfirmed.count
  }));

  return (
    <div className="p-4">
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        {/* Summary Cards */}
        <div className="bg-white p-4 rounded-lg shadow">
          <h3 className="text-lg font-semibold mb-2">Overview</h3>
          <div className="grid grid-cols-2 gap-4">
            <div className="bg-blue-50 p-3 rounded">
              <div className="text-sm text-gray-600">Total Requests</div>
              <div className="text-xl font-bold">{reportData.trends.total_requests}</div>
            </div>
            <div className="bg-green-50 p-3 rounded">
              <div className="text-sm text-gray-600">Total Amount</div>
              <div className="text-xl font-bold">${reportData.trends.total_amount.toLocaleString()}</div>
            </div>
          </div>
        </div>

        {/* Time Frame Selector */}
        <div className="bg-white p-4 rounded-lg shadow">
          <h3 className="text-lg font-semibold mb-2">Time Frame</h3>
          <select
            className="w-full p-2 border rounded"
            value={timeFrame}
            onChange={(e) => setTimeFrame(e.target.value)}
          >
            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
            <option value="monthly">Monthly</option>
            <option value="yearly">Yearly</option>
          </select>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {/* Status Distribution Pie Chart */}
        <div className="bg-white p-4 rounded-lg shadow">
          <h3 className="text-lg font-semibold mb-4">Payment Status Distribution</h3>
          <ResponsiveContainer width="100%" height={300}>
            <PieChart>
              <Pie
                data={statusData}
                cx="50%"
                cy="50%"
                labelLine={false}
                label={({ name, percent }) => `${name} (${(percent * 100).toFixed(0)}%)`}
                outerRadius={80}
                fill="#8884d8"
                dataKey="value"
              >
                {statusData.map((entry, index) => (
                  <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                ))}
              </Pie>
              <Tooltip />
              <Legend />
            </PieChart>
          </ResponsiveContainer>
        </div>

        {/* Time Series Chart */}
        <div className="bg-white p-4 rounded-lg shadow">
          <h3 className="text-lg font-semibold mb-4">Payment Trends</h3>
          <ResponsiveContainer width="100%" height={300}>
            <LineChart data={timeSeriesData}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="period" />
              <YAxis />
              <Tooltip />
              <Legend />
              <Line type="monotone" dataKey="Pending" stroke="#FFBB28" />
              <Line type="monotone" dataKey="Paid" stroke="#00C49F" />
              <Line type="monotone" dataKey="Unconfirmed" stroke="#0088FE" />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </div>
    </div>
  );
};

export default PaymentDashboard;