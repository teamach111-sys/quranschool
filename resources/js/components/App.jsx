import React, { useState } from 'react';

export default function App() {
    const [count, setCount] = useState(0);

    return (
        <div className="min-h-screen flex items-center justify-center bg-gray-50">
            <div className="bg-white p-8 rounded-xl shadow-md text-center">
                <h1 className="text-3xl font-bold mb-4 text-gray-800">
                    React + Laravel
                </h1>
                <p className="text-gray-600 mb-6">
                    React is now set up and ready to use in your Laravel application.
                </p>
                <div className="flex items-center justify-center gap-4">
                    <button
                        onClick={() => setCount((c) => c - 1)}
                        className="px-4 py-2 rounded-lg bg-gray-200 hover:bg-gray-300 transition text-gray-800 font-medium"
                    >
                        &minus;
                    </button>
                    <span className="text-2xl font-bold w-12">{count}</span>
                    <button
                        onClick={() => setCount((c) => c + 1)}
                        className="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 transition text-white font-medium"
                    >
                        +
                    </button>
                </div>
            </div>
        </div>
    );
}
