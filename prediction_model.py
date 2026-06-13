import sys
import json
import pandas as pd
from sklearn.ensemble import RandomForestRegressor
import warnings
import os

# Suppress warnings for cleaner output
warnings.filterwarnings('ignore')

def predict(occupancy, capacity, food_stock, medicine_stock, active_volunteers, pending_tasks):
    # Determine absolute path to the dataset
    # script_dir = os.path.dirname(os.path.abspath(__file__)) # __file__ is not defined in Colab
    dataset_path = 'resource_demand_dataset.csv' # Assuming the dataset is in the current working directory

    try:
        # Read dataset
        df = pd.read_csv(dataset_path)
    except FileNotFoundError:
        print(json.dumps({"error": "Dataset not found. Please run generate_dataset.py first." }))
        return

    # Features (X) and Targets (y)
    # We will use these columns to predict
    features = ['occupancy', 'capacity', 'food_stock', 'medicine_stock', 'active_volunteers', 'pending_tasks']
    X = df[features]

    # We want to predict: days_to_food_shortage, days_to_medicine_shortage, volunteer_shortage
    y_food = df['days_to_food_shortage']
    y_medicine = df['days_to_medicine_shortage']
    y_volunteer = df['volunteer_shortage']

    # Train simple models
    model_food = RandomForestRegressor(n_estimators=50, random_state=42)
    model_food.fit(X, y_food)

    model_medicine = RandomForestRegressor(n_estimators=50, random_state=42)
    model_medicine.fit(X, y_medicine)

    model_volunteer = RandomForestRegressor(n_estimators=50, random_state=42)
    model_volunteer.fit(X, y_volunteer)

    # Prepare input for prediction
    input_data = pd.DataFrame([
        {
            'occupancy': occupancy,
            'capacity': capacity,
            'food_stock': food_stock,
            'medicine_stock': medicine_stock,
            'active_volunteers': active_volunteers,
            'pending_tasks': pending_tasks
        }
    ])

    # Predict
    food_days_left = model_food.predict(input_data)[0]
    medicine_days_left = model_medicine.predict(input_data)[0]
    volunteers_needed = model_volunteer.predict(input_data)[0]

    # Calculate occupancy status
    occupancy_percentage = (occupancy / capacity) * 100 if capacity > 0 else 100

    # Format output
    result = {
        "food_days_left": round(food_days_left, 1),
        "medicine_days_left": round(medicine_days_left, 1),
        "volunteers_needed": max(0, int(round(volunteers_needed))),
        "occupancy_percentage": round(occupancy_percentage, 1)
    }

    print(json.dumps(result))

if __name__ == '__main__':
    # If arguments are provided (e.g. from PHP), use them
    if len(sys.argv) == 7:
        try:
            occupancy = float(sys.argv[1])
            capacity = float(sys.argv[2])
            food_stock = float(sys.argv[3])
            medicine_stock = float(sys.argv[4])
            active_volunteers = float(sys.argv[5])
            pending_tasks = float(sys.argv[6])
        except ValueError:
            print(json.dumps({"error": "All arguments must be numbers." }))
            sys.exit(1)
    else:
        # Default mock values for testing in an IDE or Colab directly
        occupancy = 250.0
        capacity = 500.0
        food_stock = 500.0
        medicine_stock = 50.0
        active_volunteers = 10.0
        pending_tasks = 20.0
        # Optional: Print a warning to stderr so it doesn't break JSON parsing in PHP
        print("Warning: No arguments provided. Using default mock values for testing.", file=sys.stderr)

    predict(occupancy, capacity, food_stock, medicine_stock, active_volunteers, pending_tasks)